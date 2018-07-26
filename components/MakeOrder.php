<?php namespace Lovata\OrdersShopaholic\Components;

use Input;
use Redirect;

use Kharanenka\Helper\Result;
use Lovata\Toolbox\Classes\Helper\UserHelper;
use Lovata\Toolbox\Classes\Component\ComponentSubmitForm;
use Lovata\Toolbox\Traits\Helpers\TraitValidationHelper;

use Lovata\Shopaholic\Models\Settings;
use Lovata\OrdersShopaholic\Models\ShippingType;
use Lovata\OrdersShopaholic\Classes\Processor\OrderProcessor;

/**
 * Class MakeOrder
 * @package Lovata\OrdersShopaholic\Components
 * @author  Andrey Kharanenka, a.khoronenko@lovata.com, LOVATA Group
 */
class MakeOrder extends ComponentSubmitForm
{
    use TraitValidationHelper;

    protected $bCreateNewUser = true;

    protected $arOrderData;
    protected $arUserData;

    /** @var \Lovata\Buddies\Models\User */
    protected $obUser;

    /** @var \Lovata\OrdersShopaholic\Interfaces\PaymentGatewayInterface|null */
    protected $obPaymentGateway;

    /**
     * @return array
     */
    public function componentDetails()
    {
        return [
            'name'        => 'lovata.ordersshopaholic::lang.component.make_order_name',
            'description' => 'lovata.ordersshopaholic::lang.component.make_order_description',
        ];
    }

    /**
     * @return array
     */
    public function defineProperties()
    {
        $arResult = $this->getModeProperty();

        return $arResult;
    }

    /**
     * Get redirect page property list
     * @return array
     */
    protected function getRedirectPageProperties()
    {
        if (!Result::status()) {
            return [];
        }

        $arResult = Result::data();
        if (empty($arResult) || !is_array($arResult)) {
            return [];
        }

        return $arResult;
    }

    /**
     * Init plugin method
     */
    public function init()
    {
        $this->bCreateNewUser = Settings::getValue('create_new_user');
        $this->obUser = UserHelper::instance()->getUser();

        parent::init();
    }

    /**
     * Create new order
     * @return \Illuminate\Http\RedirectResponse|null
     * @throws \Exception
     */
    public function onRun()
    {
        if ($this->sMode != self::MODE_SUBMIT) {
            return null;
        }

        $arOrderData = (array) Input::get('order');
        $arUserData = (array) Input::get('user');
        if (empty($arOrderData) && empty($arUserData)) {
            return null;
        }

        $this->create($arOrderData, $arUserData);
        if (empty($this->obPaymentGateway) || !Result::status()) {
            return $this->getResponseModeForm();
        }

        if ($this->obPaymentGateway->isRedirect()) {
            $sRedirectURL = $this->obPaymentGateway->getRedirectURL();

            return Redirect::to($sRedirectURL);
        } else if ($this->obPaymentGateway->isSuccessful()) {
            Result::setTrue($this->obPaymentGateway->getResponse());
        } else {
            Result::setFalse($this->obPaymentGateway->getResponse());
        }

        Result::setMessage($this->obPaymentGateway->getMessage())->get();

        return $this->getResponseModeForm();
    }

    /**
     * Create new order (AJAX)
     * @return \Illuminate\Http\RedirectResponse|array
     * @throws \Exception
     */
    public function onCreate()
    {
        $arOrderData = (array) Input::get('order');
        $arUserData = (array) Input::get('user');

        $this->create($arOrderData, $arUserData);
        if (empty($this->obPaymentGateway) || !Result::status()) {
            return $this->getResponseModeAjax();
        }

        if ($this->obPaymentGateway->isRedirect()) {
            $sRedirectURL = $this->obPaymentGateway->getRedirectURL();

            return Redirect::to($sRedirectURL);
        } else if ($this->obPaymentGateway->isSuccessful()) {
            Result::setTrue($this->obPaymentGateway->getResponse());
        } else {
            Result::setFalse($this->obPaymentGateway->getResponse());
        }

        Result::setMessage($this->obPaymentGateway->getMessage())->get();

        return $this->getResponseModeAjax();
    }

    /**
     * Create new order
     * @param array $arOrderData
     * @param array $arUserData
     * @throws \Exception
     * @return \Lovata\OrdersShopaholic\Models\Order|null
     */
    public function create($arOrderData, $arUserData)
    {
        $this->arOrderData = (array) $arOrderData;
        $this->arUserData = (array) $arUserData;

        //Find or create new user
        if (empty($this->obUser) && $this->bCreateNewUser) {
            $this->findOrCreateUser();
        } else if (!empty($this->obUser)) {

            $this->arUserData = [
                'email'       => $this->obUser->email,
                'name'        => $this->obUser->name,
                'last_name'   => $this->obUser->last_name,
                'middle_name' => $this->obUser->middle_name,
                'phone'       => $this->obUser->phone,
            ];
        }

        if (!Result::status()) {
            return null;
        }

        $arOrderData = $this->arOrderData;
        if (!isset($arOrderData['property']) || !is_array($arOrderData['property'])) {
            $arOrderData['property'] = [];
        }

        if (!empty($this->arUserData)) {
            $arOrderData['property'] = array_merge($arOrderData['property'], $this->arUserData);
        }

        $arPaymentData = Input::get('payment');
        if (!empty($arPaymentData) && is_array($arPaymentData)) {
            $arOrderData['payment_data'] = $arPaymentData;
        }

        $arOrderData['shipping_price'] = $this->getShippingTypePrice();

        $obOrder = OrderProcessor::instance()->create($arOrderData, $this->obUser);
        $this->obPaymentGateway = OrderProcessor::instance()->getPaymentGateway();

        return $obOrder;
    }

    /**
     * Find user by request data or create new user
     */
    protected function findOrCreateUser()
    {
        $sUserPluginName = UserHelper::instance()->getPluginName();
        if (!empty($this->obUser) || empty($this->arUserData) || empty($sUserPluginName)) {
            return;
        }

        $this->findUserByEmail();
        if (!empty($this->obUser) || !$this->bCreateNewUser) {
            return;
        }

        $this->createUser();
    }

    /**
     * Find user by email
     * @return void
     */
    protected function findUserByEmail()
    {
        if (empty($this->arUserData) || !isset($this->arUserData['email']) || empty($this->arUserData['email'])) {
            return;
        }

        //Find user by email
        $sEmail = $this->arUserData['email'];
        $this->obUser = UserHelper::instance()->findUserByEmail($sEmail);

        //if Buddies plugin is installed, then we need to process "phone" field
        if (UserHelper::instance()->getPluginName() == 'Lovata.Buddies') {
            $this->processUserPhone();
            $this->processUserPhoneList();
        }
    }

    /**
     * Process user phone
     */
    protected function processUserPhone()
    {
        if (empty($this->obUser) || empty($this->arUserData)) {
            return;
        }

        if (!isset($this->arUserData['phone']) || empty($this->arUserData['phone'])) {
            return;
        }

        $sPhone = $this->arUserData['phone'];

        $arPhoneList = $this->obUser->phone_list;
        $arPhoneList[] = $sPhone;
        $arPhoneList = array_unique($arPhoneList);

        $this->obUser->phone_list = $arPhoneList;
        $this->obUser->save();
    }

    /**
     * Process user phone list
     */
    protected function processUserPhoneList()
    {
        if (empty($this->obUser) || empty($this->arUserData)) {
            return;
        }

        if (!isset($this->arUserData['phone_list']) || empty($this->arUserData['phone_list'])) {
            return;
        }

        $arRequestPhoneList = $this->arUserData['phone_list'];

        $arPhoneList = $this->obUser->phone_list;
        $arPhoneList = array_merge($arPhoneList, $arRequestPhoneList);
        $arPhoneList = array_unique($arPhoneList);

        $this->obUser->phone_list = $arPhoneList;
        $this->obUser->save();
    }

    /**
     * Create new user
     * @return void
     */
    protected function createUser()
    {
        if (empty($this->arUserData)) {
            return;
        }

        $sPassword = md5(microtime(true));

        //Get user email
        if (Settings::getValue('generate_fake_email') && (!isset($this->arUserData['email']) || empty($this->arUserData['email']))) {
            $this->arUserData['email'] = 'fake'.$sPassword.'@fake.com';
        }

        $arUserData = $this->arUserData;
        $arUserData['password'] = $sPassword;
        $arUserData['password_confirmation'] = $sPassword;

        try {
            //Create new user
            $this->obUser = UserHelper::instance()->register($arUserData, true);
        } catch (\October\Rain\Database\ModelException $obException) {
            $this->processValidationError($obException);
            return;
        }
    }

    /**
     * Get shipping type price
     * @return float|string
     */
    protected function getShippingTypePrice()
    {
        //Get shipping price from request
        $fPrice =  Input::get('order.shipping_price');
        if ($fPrice !== null) {
            return $fPrice;
        }

        //Get shipping type ID
        $iShippingTypeID = Input::get('order.shipping_type_id');
        if (empty($iShippingTypeID)) {
            return 0;
        }

        //Get shipping type object
        $obShippingType = ShippingType::find($iShippingTypeID);
        if (empty($obShippingType)) {
            return 0;
        }

        return $obShippingType->price_value;
    }
}
