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
    // был неправильный конструктор он просто принимал по факту переменные и никуда их не складывал для дальнейшего использования в классе      
    // public function __construct(
    //     private ContentPlanRepository $repository,
    //     private EntityManagerInterface $em,
    //     private LoggerInterface $log,
    //     private Service $service,
    //     private UserOptions $userOptions,
    //     private UserCurrency $userCurrency,
    //     private Registry $doctrine,
    //     private Payments $payments,
    //     private StripeClient $stripe,
    // ) {
    // }

    //добавим приватных полей с типами для дальнейшей инцилизации 
    private ContentPlanRepository $repository;
    private EntityManagerInterface $em;
    private LoggerInterface $log;
    private Service $service;
    private Registry $doctrine;

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

    // вынесенный колбек для транзакций  не знал как назвать по этому такое вот название 

    // private function transactionalCallBack(&$userPlan, int $userId)
    // {
    //     if ($userPlan->getSubscriptionUpdateStatus() == 'in_progress') {
    //         $this->log->error('Subscription already in progress.', ['userId' => $userId]);
    //         return false;
    //     }

    //     $userPlan->setSubscriptionUpdateStatus('in_progress');
    //     $this->em->persist($userPlan);
    //     $this->em->flush();

    //     return true;
    // }



    // метод для замены кода везде где используется  
    //
    // $userPlan->setSubscriptionUpdateStatus('in_progress');
    // $this->em->persist($userPlan);
    // $this->em->flush();
    //
    // private function updateStatus($contentPlan, String $status)
    // {
    //     $contentPlan->setSubscriptionUpdateStatus($status);
    //     $this->em->persist($contentPlan);
    //     $this->em->flush();
    // }

    //патерн екзекутер круто но лучше сделать по старому что б это было явно по этому предложил бы вынести в публичный метод execute или run 
    public function __invoke(ExpireContentPlan $message)
    {
        $contentPlanId = $message->getId();
        $contentPlan = $this->repository->find($contentPlanId);

        //вынесли этот код из колбека так как колбек принимает в себя userPlan по этому сюда вынесим что б он мог его use 

        //переменную $client использовать везде где пишется $contentPlan->getClient() 
        $client = $contentPlan->getClient();
        $userId = $client->id;

        $userPlanRepository = $this->doctrine->getRepository(UserPlan::class);
        $userPlan = $userPlanRepository->find($contentPlan->getClient()->getUserPlan()->getId(), LockMode::PESSIMISTIC_WRITE);
        $userId = $contentPlan->getClient()->id;


        //в отдельный колбек можно вытащить 

        // $lock = $this->em->getConnection()->transactional(
        //     $this->transactionalCallBack($userPlan, $userId)
        // );


        //добавим использование userid и убрать  $contentPlan так как вытащили выше то что требовалось 
        $lock = $this->em->getConnection()->transactional(function ($connection) use (&$userPlan, $userId) {

            // код выглядит очень плохо колбек я бы вынес в отдельную функцию  но постраемся переделать тут 
            // $userId = $contentPlan->getClient()->getId();
            // /* @var $userPlan UserPlanEntity */
            // $userPlanRepository = $this->doctrine->getRepository(UserPlan::class);
            // $userPlan = $userPlanRepository->find($contentPlan->getClient()->getUserPlan()->getId(), LockMode::PESSIMISTIC_WRITE);

            // if ($userPlan->getSubscriptionUpdateStatus() == 'in_progress') {
            //     $this->log->error('Subscription already in progress.', ['userId' => $userId]);
            //     return false;
            // }
            // + userId используется не только тут по этому вынесем выше    
            // $userId = $contentPlan->getClient()->id;

            /* @var $userPlan UserPlanEntity */
            //этот блок кода мы вынесли выше 
            // $userPlanRepository = $this->doctrine->getRepository(UserPlan::class);
            // $userPlan = $userPlanRepository->find($contentPlan->getClient()->getUserPlan()->getId(), LockMode::PESSIMISTIC_WRITE);

            if ($userPlan->getSubscriptionUpdateStatus() == 'in_progress') {
                $this->log->error('Subscription already in progress.', ['userId' => $userId]);
                return false;
            }

            //подобные куски кода вынести в отдельный метод так как логика его повторяется
            //
            // $this->updateStatus(.... ,  .....);
            $userPlan->setSubscriptionUpdateStatus('in_progress');
            $this->em->persist($userPlan);
            $this->em->flush();

            return true;
        });

        if (!$lock) {
            // нужно добавить наверное ексепшн или какой нибудь возвращаемый тип по типу
            $this->log->error('Subscription already in progress.', ['userId' => $userId]);
            throw new Exception('Subscription already in progress.userId =>' . $userId);
            //или 
            // return $lock  
            // что бы в дальнейшем можно было бы отработать експшн выше 
            return;
        }

        // get last content plan for the user: limit 1, content plan id DESC, content_plan_id > $contentPlan->getId()
        $nextContentPlan = $this->repository->getNext($contentPlan);



        // такой логики на проверку слишком много в коде может быть тогда проще вынести его в какой нибудь патерн матчинг 
        //или сделать через какой нибудь
        //switch case но я не думаю что тут прям такое требуется 
        if (in_array($nextContentPlan->getStatus(), ['progress', 'done'])) {
            // $contentPlan->getClient()->getUserPlan()->setSubscriptionUpdateStatus('ready');
            // тут наверное имелоссь ввиду что следующему плану будет выдан статус ready  
            //а так же не хватает сохранения в персист для дальнейшей записи если так оставить то он возьмёт запись из колбека полследнюю которая осталась в обьекте  

            //$this->updateStatus(.... ,  .....);
            $nextContentPlan->getClient()->getUserPlan()->setSubscriptionUpdateStatus('ready');
            $this->em->persist($nextContentPlan);
            $this->em->flush();
            return;
        }

        if ($nextContentPlan->getStatus() == 'paid') {
            // copy all settings from the current user's content plan to the next content plan
            $this->service->prepareNextContentPlan($nextContentPlan, $contentPlan);
        } else {
            // уже установлено выше 
            // $userId = $contentPlan->getClient()->getId();
            $autorenew = $this->userOptions->getByUserId($userId)->get('has_subscription');
            //наверное тут должно было прдти булевое значение и если там есть сабскрайб то срабатывает по этому меняем на === за место !=
            if ($autorenew === 1) {
                $userPlan = $contentPlan->getClient()->getUserPlan();
                //$this->updateStatus(.... ,  .....);
                $userPlan->setSubscriptionUpdateStatus('ready');
                $this->em->persist($userPlan);
                $this->em->flush();
                return;
            } else {
                //предлогаю вынести в отдельный класс такое  слишком уж странно что логика payment присутствует в классе подписок 
                //и дать какой то метод который вернёт нам значение  переменной $payment
                $payment = $this->createNewPaymentUseCase->execute($userId);


                //     добавить лог есть payment как то вернул пустоту  и вывести в лог 
                // if (empty($payment)) {
                //     $this->log->error('no payments return', ['userId' => $userId]);
                //     return;
                // }



                $this->service->createContentPlan($contentPlan->getClient(), $contentPlan->getNumAccounts(), $payment);
                return;
            }

            /* @var $lastPlanPayment Payment */
            // $paymentRepository = $this->doctrine->getRepository(Payment::class);
            // $lastPayment = $paymentRepository->getLastPayment($contentPlan->getClient()->getId());

            // $product = $this->em->find(Product::class, $lastPayment->getProduct()->getId());

            // if (!$product) {
            //     throw new Exception("Product not found: [{$lastPayment->getProduct()->getId()}].");
            // }

            // $currency = strtolower($this->userCurrency->getByUserId($contentPlan->getClient()->getId()));
            // $amount = $product->getPrice($currency);

            // /* @var $stripeCustomerRepository StripeCustomerRepository */
            // $stripeCustomerRepository = $this->doctrine->getRepository(StripeCustomer::class);
            // $stripeCustomer = $stripeCustomerRepository->getCustomerByUserId($contentPlan->getClient()->getId());
            // if (!$stripeCustomer) {
            //     throw new Exception("Not found stripe customer. UserId: [{$contentPlan->getClient()->getId()}].");
            // }

            // $defaultPaymentMethodId = $this->payments->getDefaultPaymentMethodId($contentPlan->getClient()->getId());
            // if (!$defaultPaymentMethodId) {
            //     throw new Exception("Not found default payment method. UserId: [{$contentPlan->getClient()->getId()}].");
            // }



            // try {
            //     $paymentIntent = $this->stripe->paymentIntents->create(
            //         [
            //             // ignore array, no need for the testing task
            //             'customer' => $stripeCustomer->getStripeCustomerId(),
            //             'payment_method' => $defaultPaymentMethodId,
            //             'amount' => intval(floatval($amount) * 100),
            //             'currency' => $currency,
            //         ]
            //     );
            // } catch (CardException $e) {
            //     $this->log->error(
            //         'CRON_SUBSCRIPTION',
            //         [
            //             // ignore array, no need for the testing task
            //         ]
            //     );

            //     $contentPlan->getClient()->getUserPlan()->setSubscriptionUpdateStatus('ready');
            //     $this->em->flush();

            //     return;
            // }

            // $payment = new Payment();
            // $payment->setStripeCustomerId($stripeCustomer->getStripeCustomerId());
            // $payment->setSystem('stripe');
            // $payment->setUser($contentPlan->getClient());
            // $payment->setPaymentStatus($paymentIntent->status);
            // // ...., set other $paymentIntent options
            // $this->payments->savePayment($payment);

            // $this->log->error(
            //     'CRON_SUBSCRIPTION',
            //     [
            //         // ignore array, no need for the testing task
            //     ]
            // );

            // ниже код ушёл выше 
            // create content plan with status "new" for the current user
            // $this->service->createContentPlan($contentPlan->getClient(), $contentPlan->getNumAccounts(), $payment);
        }

        $userPlan = $contentPlan->getClient()->getUserPlan();
        //$this->updateStatus(.... ,  .....);
        $userPlan->setSubscriptionUpdateStatus('ready');
        //добавлен persist
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

                    // оставил бы только int float не должен храниться в бд при подсчёте денег 
                    //хорошим тоном было бы сохранять в копейках пенях тыйнах или прочих расчётных разметных еденицах 

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
