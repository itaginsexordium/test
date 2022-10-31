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
use ConteniveApp\Entity\UserPlan as UserPlanEntity;
use ConteniveApp\Message\ExpireContentPlan;
use ConteniveApp\Repository\ContentPlanRepository;
use Psr\Log\LoggerInterface;
use Stripe\Exception\CardException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use App\ContentPlan\Service;
use App\User\Options\Service as UserOptions;
use App\Payment\Service as Payments;
use Stripe\StripeClient;

final class ExpireContentPlanHandler implements MessageHandlerInterface
{
    public function __construct(
        private ContentPlanRepository $repository,
        private EntityManagerInterface $em,
        private LoggerInterface $log,
        private Service $service,
        private UserOptions $userOptions,
        private UserCurrency $userCurrency,
        private Registry $doctrine,
        private Payments $payments,
        private StripeClient $stripe,
    ) {
    }

    public function __invoke(ExpireContentPlan $message)
    {
        $contentPlanId = $message->getId();
        $contentPlan = $this->repository->find($contentPlanId);

        $lock = $this->em->getConnection()->transactional(function ($connection) use ($contentPlan, &$userPlan) {
            $userId = $contentPlan->getClient()->getId();
            /* @var $userPlan UserPlanEntity */
            $userPlanRepository = $this->doctrine->getRepository(UserPlan::class);
            $userPlan = $userPlanRepository->find($contentPlan->getClient()->getUserPlan()->getId(), LockMode::PESSIMISTIC_WRITE);
            
            if ($userPlan->getSubscriptionUpdateStatus() == 'in_progress') {
                $this->log->error('Subscription already in progress.', ['userId' => $userId]);
                return false;
            }
            
            $userPlan->setSubscriptionUpdateStatus('in_progress');
            $this->em->persist($userPlan);
            $this->em->flush();

            return true;
        });

        if (!$lock) {
            return;
        }

        // get last content plan for the user: limit 1, content plan id DESC, content_plan_id > $contentPlan->getId()
        $nextContentPlan = $this->repository->getNext($contentPlan);

        if (in_array($nextContentPlan->getStatus(), ['progress', 'done'])) {
            $contentPlan->getClient()->getUserPlan()->setSubscriptionUpdateStatus('ready');
            $this->em->flush();
            return;
        }

        if ($nextContentPlan->getStatus() == 'paid') {
            // copy all settings from the current user's content plan to the next content plan
            $this->service->prepareNextContentPlan($nextContentPlan, $contentPlan);
        } else {
            $userId = $contentPlan->getClient()->getId();
            $autorenew = $this->userOptions->getByUserId($userId)->get('has_subscription');
            if ($autorenew != 1) {
                $userPlan = $contentPlan->getClient()->getUserPlan();
                $userPlan->setSubscriptionUpdateStatus('ready');
                $this->em->flush();
                return;
            }

            /* @var $lastPlanPayment Payment */            
            $paymentRepository = $this->doctrine->getRepository(Payment::class);
            $lastPayment = $paymentRepository->getLastPayment($contentPlan->getClient()->getId());

            $product = $this->em->find(Product::class, $lastPayment->getProduct()->getId());

            if (!$product) {
                throw new Exception("Product not found: [{$lastPayment->getProduct()->getId()}].");
            }

            $currency = strtolower($this->userCurrency->getByUserId($contentPlan->getClient()->getId()));
            $amount = $product->getPrice($currency);

            /* @var $stripeCustomerRepository StripeCustomerRepository */
            $stripeCustomerRepository = $this->doctrine->getRepository(StripeCustomer::class);
            $stripeCustomer = $stripeCustomerRepository->getCustomerByUserId($contentPlan->getClient()->getId());
            if (!$stripeCustomer) {
                throw new Exception("Not found stripe customer. UserId: [{$contentPlan->getClient()->getId()}].");
            }

            $defaultPaymentMethodId = $this->payments->getDefaultPaymentMethodId($contentPlan->getClient()->getId());
            if (!$defaultPaymentMethodId) {
                throw new Exception("Not found default payment method. UserId: [{$contentPlan->getClient()->getId()}].");
            }

            try {
                $paymentIntent = $this->stripe->paymentIntents->create(
                    [
                        // ignore array, no need for the testing task
                        'customer' => $stripeCustomer->getStripeCustomerId(),
                        'payment_method' => $defaultPaymentMethodId,
                        'amount' => intval(floatval($amount) * 100),
                        'currency' => $currency,                        
                    ]
                );
            } catch (CardException $e) {
                $this->log->error(
                    'CRON_SUBSCRIPTION',
                    [
                        // ignore array, no need for the testing task
                    ]
                );

                $contentPlan->getClient()->getUserPlan()->setSubscriptionUpdateStatus('ready');
                $this->em->flush();
                
                return;
            }

            $payment = new Payment();
            $payment->setStripeCustomerId($stripeCustomer->getStripeCustomerId());
            $payment->setSystem('stripe');
            $payment->setUser($contentPlan->getClient());
            $payment->setPaymentStatus($paymentIntent->status);
            // ...., set other $paymentIntent options
            $this->payments->savePayment($payment);

            $this->log->error(
                'CRON_SUBSCRIPTION',
                [
                    // ignore array, no need for the testing task
                ]
            );

            // create content plan with status "new" for the current user
            $this->service->createContentPlan($contentPlan->getClient(), $contentPlan->getNumAccounts(), $payment);
        }

        $userPlan = $contentPlan->getClient()->getUserPlan();
        $userPlan->setSubscriptionUpdateStatus('ready');
        $this->em->flush();
    }
   
}