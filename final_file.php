<?php

declare(strict_types=1);

namespace ConteniveApp\MessageHandler;

use App\Payment\UserCurrency;
use Doctrine\Persistence\ManagerRegistry as Registry;
use Exception;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use ConteniveApp\Entity\ContentPlan;
use ConteniveApp\Entity\Payment;
use ConteniveApp\Entity\Product;
use ConteniveApp\Entity\StripeCustomer;
use ConteniveApp\Entity\UserPlan;
use ConteniveApp\Message\ExpireContentPlan;
use ConteniveApp\Repository\ContentPlanRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use App\ContentPlan\Service;
use App\User\Options\Service as UserOptions;
use App\Payment\Service as Payments;
use Stripe\StripeClient;

final class ExpireContentPlanHandler implements MessageHandlerInterface
{
    public ContentPlanRepository $repository;
    public EntityManagerInterface $em;
    public LoggerInterface $log;
    public Service $service;
    public Registry $doctrine;
    public UserOptions $userOptions;
    public CreateNewPaymentUseCase $createNewPaymentUseCase;

    public function __construct(
        ContentPlanRepository $repository,
        EntityManagerInterface $em,
        LoggerInterface $log,
        Service $service,
        Registry $doctrine,
        UserOptions $userOptions,
        CreateNewPaymentUseCase $createNewPaymentUseCase
    ) {
        $this->repository = $repository;
        $this->em = $em;
        $this->log = $log;
        $this->service = $service;
        $this->doctrine = $doctrine;
        $this->userOptions = $userOptions;

        //альтернативный класс для одного кодового блока 
        $this->createNewPaymentUseCase = $createNewPaymentUseCase;
    }

    private function transactionalCallBack(&$userPlan, int $userId)
    {
        if ($userPlan->getSubscriptionUpdateStatus() == 'in_progress') {
            $this->log->error('Subscription already in progress.', ['userId' => $userId]);
            return false;
        }

        $this->updateStatus($userPlan, 'in_progress');
        return true;
    }

    private function updateStatus($contentPlan, String $status)
    {
        $contentPlan->setSubscriptionUpdateStatus($status);
        $this->em->persist($contentPlan);
        $this->em->flush();
    }

    public function __invoke(ExpireContentPlan $message)
    {
        $contentPlanId = $message->getId();

        $contentPlan = $this->repository->find($contentPlanId);
        $client = $contentPlan->getClient();
        $userId = $client->id;

        $userPlanRepository = $this->doctrine->getRepository(UserPlan::class);
        $userPlan = $userPlanRepository->find(
            $client
                ->getUserPlan()
                ->getId(),
            LockMode::PESSIMISTIC_WRITE
        );

        $lock = $this->em->getConnection()->transactional(
            $this->transactionalCallBack($userPlan, $userId)
        );

        if (!$lock) {
            $this->log->error('Subscription already in progress.', ['userId' => $userId]);
            throw new Exception('Subscription already in progress.userId =>' . $userId);
        }

        $nextContentPlan = $this->repository->getNext($contentPlan);
        //должен ли тут быть in_progress не известно возможно есть ещё и просто статус progress 
        if (in_array($nextContentPlan->getStatus(), ['progress', 'done'])) {
            $this->updateStatus($nextContentPlan->getClient()->getUserPlan(), 'ready');
            return;
        }

        if ($nextContentPlan->getStatus() === 'paid') {
            $this->service->prepareNextContentPlan($nextContentPlan, $contentPlan);
        } else {
            $autorenew = $this->userOptions->getByUserId($userId)->get('has_subscription');
            if ($autorenew === 1) {
                $this->updateStatus($userPlan, 'ready');
                return;
            } else {
                $payment = $this->createNewPaymentUseCase->execute($client);

                if (empty($payment)) {
                    $this->log->error('no payments return', ['userId' => $userId]);
                    return;
                }

                $this->service->createContentPlan($client, $contentPlan->getNumAccounts(), $payment);
                return;
            }
        }

        $userPlan = $client->getUserPlan();
        $this->updateStatus($userPlan, 'ready');
    }
}


class CreateNewPaymentUseCase
{
    public $repository;
    public $log;
    public $userCurrency;
    public $doctrine;
    public $payments;
    public $stripe;

    public function __construct(
        ContentPlanRepository $repository,
        LoggerInterface $log,
        UserCurrency $userCurrency,
        Registry $doctrine,
        Payments $payments,
        StripeClient $stripe
    ) {
        $this->repository = $repository;
        $this->log = $log;
        $this->userCurrency = $userCurrency;
        $this->doctrine = $doctrine;
        $this->payments = $payments;
        $this->stripe = $stripe;
    }

    public function getNewPayment()
    {
        if (empty($this->Payment)) {
            $this->Payment = new Payment();
        }
        return $this->Payment;
    }

    public function execute($client): Payment
    {
        $userId = (int) $client->id;

        try {
            /* @var $lastPlanPayment Payment */
            $paymentRepository = $this->doctrine->getRepository(Payment::class);
            $lastPayment = $paymentRepository->getLastPayment($userId);

            $product = $this->em->find(Product::class, $lastPayment->getProduct()->getId());

            if (!$product) {
                throw new Exception("Product not found: [{$lastPayment->getProduct()->getId()}].");
            }

            $currency = strtolower($this->userCurrency->getByUserId($userId));
            $amount = $product->getPrice($currency);

            /* @var $stripeCustomerRepository StripeCustomerRepository */
            $stripeCustomerRepository = $this->doctrine->getRepository(StripeCustomer::class);
            $stripeCustomer = $stripeCustomerRepository->getCustomerByUserId($userId);
            if (!$stripeCustomer) {
                throw new Exception("Not found stripe customer. UserId: [{$userId}].");
            }

            $defaultPaymentMethodId = $this->payments->getDefaultPaymentMethodId($userId);
            if (!$defaultPaymentMethodId) {
                throw new Exception("Not found default payment method. UserId: [{$userId}].");
            }

            $paymentIntent = $this->stripe->paymentIntents->create(
                [
                    // ignore array, no need for the testing task
                    'customer' => $stripeCustomer->getStripeCustomerId(),
                    'payment_method' => $defaultPaymentMethodId,
                    'amount' => intval(floatval($amount) * 100),
                    'currency' => $currency,
                ]
            );
        } catch (Exception $e) {
            $this->log->error(
                '' . $e,
                [
                    // ignore array, no need for the testing task
                ]
            );
            return null;
        }

        $payment = $this->getNewPayment();
        $payment->setStripeCustomerId($stripeCustomer->getStripeCustomerId());
        $payment->setSystem('stripe');
        $payment->setUser($client);
        $payment->setPaymentStatus($paymentIntent->status);
        // ...., set other $paymentIntent options
        $this->payments->savePayment($payment);

        return $payment;
    }
}
