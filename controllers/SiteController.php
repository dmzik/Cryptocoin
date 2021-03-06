<?php

namespace app\controllers;

use app\components\Bitcoin;
use app\models\AffiliateForm;
use app\models\Deposit;
use app\models\DepositEntity;
use app\models\DepositForm;
use app\models\PayEntity;
use app\models\UserEntity;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\filters\VerbFilter;
use app\models\LoginForm;
use app\models\ContactForm;
use yii\web\ForbiddenHttpException;

class SiteController extends Controller
{
    private $userId;

    private $user;

    private $payment;

    public function init()
    {
        $cookies = Yii::$app->request->cookies;

        $this->userId = $cookies->getValue('userId');

        if (!$this->userId) {
            $cookies = Yii::$app->response->cookies;
            $this->userId = time();

            $cookies->add(
                new \yii\web\Cookie(
                    [
                        'name' => 'userId',
                        'value' => $this->userId,
                        'expire' => time() + 60 * 60 * 24 * 365,
                    ]
                )
            );

            $user = new UserEntity();

            if(Yii::$app->request->get('ref')){
                $user->ref_id = Yii::$app->request->get('ref');
            }

            $user->user_id = $this->userId;
            $user->created_date = time();
            $user->save();
        }

        $this->user = UserEntity::find()->where(['user_id' => $this->userId])->one();

        $this->payment = new Bitcoin(
            Yii::$app->params['BTC_IPN_PASSWORD'],
            Yii::$app->params['BTC_GUID'],
            Yii::$app->params['BTC_PASSWORD'],
            Yii::$app->params['BTC_SECOND_PASSWORD']
        );

        parent::init();
    }

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'only' => ['logout'],
                'rules' => [
                    [
                        'actions' => ['logout'],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'logout' => ['post'],
                ],
            ],
        ];
    }

    public function actions()
    {
        return [
            'error' => [
                'class' => 'yii\web\ErrorAction',
            ],
            'captcha' => [
                'class' => 'yii\captcha\CaptchaAction',
                'fixedVerifyCode' => YII_ENV_TEST ? 'testme' : null,
            ],
        ];
    }

    /**
     * Main page
     * @param string $type types of viewing deposits
     * @return string|\yii\web\Response
     */
    public function actionIndex($type = "", $ref = 0)
    {
        $activeCount = DepositEntity::find()->where(['status' => Deposit::ACTIVE])->count();
        $myCount = DepositEntity::find()->where(['user_id' => $this->userId])->count();
        $payoutsCount = DepositEntity::find()->where(['status' => Deposit::FINISHED])->count();

        $query = DepositEntity::find()->where(['status' => Deposit::ACTIVE]);
        if ($type == "my") {
            $query = DepositEntity::find()->where(['user_id' => $this->userId]);
        }
        if ($type == "paid") {
            $query = DepositEntity::find()->where(['status' => Deposit::FINISHED]);
        }

        $deposits = new ActiveDataProvider(
            [
                'query' => $query,
                'sort' => false,
            ]
        );

        $depositForm = new DepositForm();

        $userAddress = $this->getAddress();
        if ($userAddress) {
            $depositForm->pay_address = $userAddress;
        }

        if ($depositForm->load(Yii::$app->request->post()) && $depositForm->validate()) {
            $depositEntity = new DepositEntity();

            $this->saveAddress($depositForm->pay_address);

            $deposit = new Deposit($depositEntity, $this->payment);
            $deposit->userId = $this->userId;
            $deposit->take($depositForm);
            $deposit->create();
            Yii::$app->session->setFlash('result', 'Address to create deposit: '.$deposit->address);

            return $this->refresh();
        }

        return $this->render(
            'index',
            [
                'depositForm' => $depositForm,
                'deposits' => $deposits,
                'type' => $type,
                'activeCount' => $activeCount,
                'myCount' => $myCount,
                'payoutsCount' => $payoutsCount,
            ]
        );
    }

    /**
     * IPN results page, notifications from payments
     * systems should go here
     */
    public function actionIncome()
    {
        $expirePeriod = Yii::$app->params['expireDepositPeriod']; // Expire period of deposits

        if ($this->payment->validate()) {
            $depositEntity = DepositEntity::find()->where(['address' => $this->payment->address])->one();
            $deposit = new Deposit($depositEntity, $this->payment);
            $deposit->calculatePayAmount($this->payment->amount);
            $deposit->start($expirePeriod);
            $deposit->payToPartner(new PayEntity(),Yii::$app->params['partnerPercent'],$this->user);

        } else {
            Yii::error('Invalid payment IPN, answer: '.$this->payment->error);
        }
    }

    /**
     * Hidden pay method to pay deposits,
     * should be get from cron job tasks
     * @param string $pass some password to open the page
     * @throws ForbiddenHttpException
     */
    public function actionPay($pass)
    {
        if ($pass != Yii::$app->params['payPassword']) {
            throw new ForbiddenHttpException("You are not allowed to see this page");
        }

        if (!Yii::$app->params['autoPay']) {
            Yii::$app->end();
        }

        $toPayDeposits = DepositEntity::find()->where('expire_date <= NOW() AND status = '.Deposit::ACTIVE)->all();

        foreach ($toPayDeposits as $depositEntity) {
            $deposit = new Deposit($depositEntity, $this->payment);
            $deposit->payAddress = $depositEntity->pay_address;
            $deposit->payAmount = $depositEntity->pay_amount / 100000000;
            $errors = $deposit->pay();

            if (!$errors) {
                $deposit->pay();
            } else {
                Yii::error("#".$depositEntity->id." ERROR: ".$errors);
            }
        }

    }

    public function actionLogin()
    {
        if (!\Yii::$app->user->isGuest) {
            return $this->goHome();
        }

        $model = new LoginForm();
        if ($model->load(Yii::$app->request->post()) && $model->login()) {
            return $this->redirect(['deposit/index']);
        }

        return $this->render(
            'login',
            [
                'model' => $model,
            ]
        );
    }

    public function actionLogout()
    {
        Yii::$app->user->logout();

        return $this->goHome();
    }

    public function actionContact()
    {
        $model = new ContactForm();
        if ($model->load(Yii::$app->request->post()) && $model->contact(Yii::$app->params['adminEmail'])) {
            Yii::$app->session->setFlash('contactFormSubmitted');

            return $this->refresh();
        }

        return $this->render(
            'contact',
            [
                'model' => $model,
            ]
        );
    }

    public function actionFaq()
    {
        return $this->render('faq');
    }

    public function actionAffiliate()
    {
        $affiliateForm = new AffiliateForm();

        if ($affiliateForm->load(Yii::$app->request->post()) && $affiliateForm->validate()) {
            $this->user->address = $affiliateForm->address;
            $this->user->updated_date = time();
            $this->user->save();

            return $this->refresh();
        }

        $pays = new ActiveDataProvider(
            [
                'query' => PayEntity::find()->where(['user_id' => $this->user->user_id]),
                'sort' => false,
            ]
        );

        return $this->render(
            'affiliate',
            ['userId' => $this->userId, 'user' => $this->user, 'affiliateForm' => $affiliateForm, 'pays' => $pays]
        );
    }

    private function saveAddress($address)
    {
        $cookies = Yii::$app->response->cookies;

        $cookies->add(
            new \yii\web\Cookie(
                [
                    'name' => 'address',
                    'value' => $address,
                    'expire' => time() + 60 * 60 * 24 * 365,
                ]
            )
        );

    }

    private function getAddress()
    {
        $cookies = Yii::$app->request->cookies;
        $address = $cookies->getValue('address');
        if ($address) {
            return $address;
        }

        return '';
    }

}
