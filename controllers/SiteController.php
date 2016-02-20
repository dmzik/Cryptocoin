<?php

namespace app\controllers;

use app\models\DepositForm;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\filters\VerbFilter;
use app\models\LoginForm;
use app\models\ContactForm;

class SiteController extends Controller
{
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

    public function actionIndex()
    {
       /* $orders = Order::find()->where(['email' => $this->user->email])->orderBy('id DESC');
        $orders = new ActiveDataProvider(
            [
                'query' => $orders,
                'sort' => false,
            ]
        );*/

        $depositForm = new DepositForm();

        if ($depositForm->load(Yii::$app->request->post()) && $depositForm->validate()) {

            /*$fileUploader->documentFile = UploadedFile::getInstance($fileUploader, 'documentFile');
            if ($fileUploader->upload()) {
                $file = new File();
                $file->create($fileUploader, $this->user->id);

                return $this->refresh();
            }*/
        }

        return $this->render('index',['depositForm'=>$depositForm]);
    }

    public function actionLogin()
    {
        if (!\Yii::$app->user->isGuest) {
            return $this->goHome();
        }

        $model = new LoginForm();
        if ($model->load(Yii::$app->request->post()) && $model->login()) {
            return $this->goBack();
        }
        return $this->render('login', [
            'model' => $model,
        ]);
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
        return $this->render('contact', [
            'model' => $model,
        ]);
    }

    public function actionAffilate()
    {
        return $this->render('affilate');
    }

    public function actionFaq()
    {
        return $this->render('faq');
    }
}
