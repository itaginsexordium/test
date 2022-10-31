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
    private ContentPlanRepository $repository;
    private EntityManagerInterface $em;
    private LoggerInterface $log;
    private Service $service;
    private Registry $doctrine;
    private UserOptions $userOptions;

    public function __construct()
    {
        $this->repository = new ContentPlanRepository();
        $this->em = new EntityManagerInterface();
        $this->log = new LoggerInterface();
        $this->service = new Service();
        $this->doctrine = new Registry();
        $this->userOptions = new UserOptions();

        //альтернативный класс для одного кодового блока 
        $this->createNewPaymentUseCase = new CreateNewPaymentUseCase();
    }

    private function transactionalCallBack(&$userPlan, int $userId)
    {
        if ($userPlan->getSubscriptionUpdateStatus() == 'in_progress') {
            $this->log->error('Subscription already in progress.', ['userId' => $userId]);
            return false;
        }

        $userPlan->setSubscriptionUpdateStatus('in_progress');
        $this->em->persist($userPlan);
        $this->em->flush();

        return true;
    }

    public function __invoke(ExpireContentPlan $message)
    {
        $contentPlanId = $message->getId();

        $contentPlan = $this->repository->find($contentPlanId);
        $userPlanRepository = $this->doctrine->getRepository(UserPlan::class);
        $userId = $contentPlan->getClient()->id;
        $userPlan = $userPlanRepository->find(
            $contentPlan->getClient()
                ->getUserPlan()
                ->getId(),
            LockMode::PESSIMISTIC_WRITE
        );


        $lock = $this->em->getConnection()->transactional(
            $this->transactionalCallBack($userPlan, $userId)
        );

        if (!$lock) {
            throw new Exception('Subscription already in progress.userId =>' . $userId);
            $this->log->error('Subscription already in progress.', ['userId' => $userId]);
        }

        $nextContentPlan = $this->repository->getNext($contentPlan);
        //должен ли тут быть in_progress не известно возможно есть ещё и просто статус progress 
        if (in_array($nextContentPlan->getStatus(), ['progress', 'done'])) {
            $nextContentPlan->getClient()->getUserPlan()->setSubscriptionUpdateStatus('ready');
            $this->em->persist($nextContentPlan);
            $this->em->flush();
            return;
        }


        if ($nextContentPlan->getStatus() === 'paid') {
            $this->service->prepareNextContentPlan($nextContentPlan, $contentPlan);
        } else {
            $autorenew = $this->userOptions->getByUserId($userId)->get('has_subscription');
            if ($autorenew === 1) {
                $userPlan->setSubscriptionUpdateStatus('ready');
                $this->em->persist($userPlan);
                $this->em->flush();
                return;
            } else {
                $payment = $this->createNewPaymentUseCase->execute($contentPlan->getClient());

                $this->service->createContentPlan($contentPlan->getClient(), $contentPlan->getNumAccounts(), $payment);
                return;
            }
        }

        $userPlan = $contentPlan->getClient()->getUserPlan();
        $userPlan->setSubscriptionUpdateStatus('ready');
        $this->em->persist($userPlan);
        $this->em->flush();
    }
}


class CreateNewPaymentUseCase
{
    public function __construct()
    {
        $this->repository = new ContentPlanRepository();
        $this->log = new LoggerInterface();
        $this->userCurrency = new UserCurrency();
        $this->doctrine = new Registry();
        $this->payments = new Payments();
        $this->stripe = new StripeClient();
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

        $payment = new Payment();
        $payment->setStripeCustomerId($stripeCustomer->getStripeCustomerId());
        $payment->setSystem('stripe');
        $payment->setUser($client);
        $payment->setPaymentStatus($paymentIntent->status);
        // ...., set other $paymentIntent options
        $this->payments->savePayment($payment);

        return $payment;
    }
}
