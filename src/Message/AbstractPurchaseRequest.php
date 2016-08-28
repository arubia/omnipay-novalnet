<?php

namespace Omnipay\Novalnet\Message;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Novalnet\AbstractGateway;
use Omnipay\Novalnet\RedirectGateway;
use Omnipay\Novalnet\XmlGateway;

/**
 * Novalnet Abstract Purchase Request
 *
 */
abstract class AbstractPurchaseRequest extends AbstractRequest
{
    /**
     * {@inheritdoc}
     */
    public function getData()
    {
        $this->validate(
        // general
            'vendorId',
            'vendorAuthcode',
            'productId',
            'tariffId',
            'amount',
            'currency',
            'transactionId',
            // customer
            'card'
        );
        $this->validateCard(array(
            'billingFirstName',
            'billingLastName',
            'billingAddress1',
            'billingPostcode',
            'billingCity',
            'billingCountry',
            'email',
            'phone',
        ));

        if ($this->shouldRedirect()) {
            $this->validate('paymentKey');
        }

        /** @var \Omnipay\Common\CreditCard $card */
        $card = $this->getCard();
        $data = array(
            'currency' => $this->getCurrency(),
            'order_no' => $this->getTransactionId(),
            'lang' => $this->getLocale() ?: 'EN',
            'test_mode' => $this->getTestMode(),
            'skip_cfm' => true,
            'skip_suc' => true,

            // customer details
            'remote_ip' => $this->httpRequest->getClientIp(),
            'first_name' => $card->getBillingFirstName(),
            'last_name' => $card->getBillingLastName(),
            'street' => $card->getBillingAddress1(),
            'search_in_street' => 1,
            'zip' => $card->getBillingPostcode(),
            'city' => $card->getBillingCity(),
            'country' => $card->getBillingCountry(),
            'country_code' => $card->getBillingCountry(),
            'email' => $card->getEmail(),
            'mobile' => $card->getBillingPhone(),
            'tel' => $card->getBillingPhone(),
            'fax' => $card->getFax(),
            'birth_date' => $card->getBirthday(),
        );

        if ($this->getPaymentMethod() != AbstractGateway::ALL_METHODS) {
            $data['key'] = $this->getPaymentMethod();

            if ($this->getChosenOnly()) {
                $data['chosen_only'] = true;
            }
        }

        $dataToEncode = array(
            'auth_code' => $this->getVendorAuthcode(),
            'product' => $this->getProductId(),
            'tariff' => $this->getTariffId(),
            'amount' => $this->getAmountInteger(),
            'encoded_amount' => $this->getAmountInteger(),
            'uniqid' => $this->getTransactionId(),
            'test_mode' => $this->getTestMode(),
        );

        if ($this->shouldRedirect() && $this->shouldEncode()) {
            $self = $this;
            $encodedData = array_map(function ($value) use ($self) {
                return $self->encode($value, $self->getPaymentKey());
            }, $dataToEncode);

            $data = array_merge($data, $encodedData, array(
                'vendor' => $this->getVendorId(),
            ));
        } elseif ($this->shouldRedirect() && !$this->shouldEncode()) {
            $data = array_merge($data, $dataToEncode, array(
                'vendor' => $this->getVendorId(),
            ));
        } else {
            $data = array_merge($data, array(
                'vendor_id' => $this->getVendorId(),
                'vendor_authcode' => $this->getVendorAuthcode(),
                'product_id' => $this->getProductId(),
                'tariff_id' => $this->getTariffId(),
                'amount' => $this->getAmountInteger(),
            ));
        }

        // set description
        if ($description = $this->getDescription()) {
            $debitReason = str_split($description, 27);
            $debitReason = array_splice($debitReason, 0, 5);

            for ($i = 1; $i <= count($debitReason); $i++) {
                $data['additional_info']['debit_reason_' . $i] = $debitReason[($i - 1)];
            }
        }

        if ($this->shouldRedirect() && $this->getReturnUrl() && $this->getCancelUrl()) {
            $data['return_url'] = $this->getReturnUrl();
            $data['return_method'] = $this->getReturnMethod() ?: 'POST';
            $data['error_return_url'] = $this->getCancelUrl();
            $data['error_return_method'] = $this->getCancelMethod() ?: 'POST';
            $data['notify_url'] = $this->getNotifyUrl();
        } elseif ($this->shouldRedirect() && (!$this->getReturnUrl() && $this->getCancelUrl())) {
            throw new InvalidRequestException('Missing return url as parameter');
        } elseif ($this->shouldRedirect() && ($this->getReturnUrl() && !$this->getCancelUrl())) {
            throw new InvalidRequestException('Missing cancel url as parameter');
        } elseif ($this->shouldRedirect()) {
            throw new InvalidRequestException('Missing return and cancel url as parameters');
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function sendData($data)
    {
        if ($this->shouldRedirect()) {
            return new PurchaseResponse($this, $data);
        }

        // send request
        $httpResponse = $this->httpClient->post($this->getEndpoint(), null, $data)->send();

        // return response
        return $this->response = new RedirectPurchaseResponse($this, $httpResponse->json());
    }

    public function getEndpoint()
    {
        switch($this->getPaymentMethod()) {
            case RedirectGateway::GIROPAY_METHOD:
                $endpoint = 'https://payport.novalnet.de/giropay';
                break;
            case RedirectGateway::IDEAL_METHOD:
            case RedirectGateway::ONLINE_TRANSFER_METHOD:
                $endpoint = 'https://payport.novalnet.de/online_transfer_payport';
                break;
            case RedirectGateway::PAYPAL_METHOD:
                $endpoint = 'https://payport.novalnet.de/paypal_payport';
                break;
            case RedirectGateway::EPS_METHOD:
                $endpoint = 'https://payport.novalnet.de/eps_payport';
                break;
            case RedirectGateway::CREDITCARD_METHOD:
                $endpoint = 'https://payport.novalnet.de/global_pci_payport';
                break;
            default:
                $endpoint = 'https://payport.novalnet.de/nn/paygate.jsp';
        }

        return $endpoint;
    }

    public function getDays()
    {
        return $this->getParameter('days');
    }

    public function setDays($value)
    {
        return $this->setParameter('days', $value);
    }

    public function getIncluding()
    {
        return $this->getParameter('including');
    }

    public function setIncluding($value)
    {
        return $this->setParameter('including', $value);
    }

    public function getEntranceCode()
    {
        return $this->getParameter('entranceCode') ?: $this->getTransactionId();
    }

    public function setEntranceCode($value)
    {
        return $this->setParameter('entranceCode', $value);
    }

    public function getMakeInvoice()
    {
        return $this->getParameter('makeInvoice');
    }

    public function setMakeInvoice($value)
    {
        return $this->setParameter('makeInvoice', $value);
    }

    public function getMailInvoice()
    {
        return $this->getParameter('mailInvoice');
    }

    public function setMailInvoice($value)
    {
        return $this->setParameter('mailInvoice', $value);
    }

    public function getBillingCountrycode()
    {
        return $this->getParameter('billingCountrycode');
    }

    public function setBillingCountrycode($value)
    {
        return $this->setParameter('billingCountrycode', $value);
    }

    public function getShippingCountrycode()
    {
        return $this->getParameter('shippingCountrycode');
    }

    public function setShippingCountrycode($value)
    {
        return $this->setParameter('shippingCountrycode', $value);
    }

    public function getTariffId()
    {
        return $this->getParameter('tariffId');
    }

    public function setTariffId($value)
    {
        return $this->setParameter('tariffId', $value);
    }

    public function getIban()
    {
        return $this->getParameter('iban');
    }

    public function setIban($value)
    {
        return $this->setParameter('iban', $value);
    }

    public function getSepaDueDate()
    {
        return $this->getParameter('sepaDueDate');
    }

    public function setSepaDueDate($value)
    {
        return $this->setParameter('sepaDueDate', date('Y-m-d', strtotime($value)));
    }

    public function getMandidateRef()
    {
        return $this->getParameter('mandidateRef');
    }

    public function setMandidateRef($value)
    {
        return $this->setParameter('mandidateRef', $value);
    }

    public function getPaymentKey()
    {
        return $this->getParameter('paymentKey');
    }

    public function setPaymentKey($value)
    {
        return $this->setParameter('paymentKey', $value);
    }

    public function getPaymentMethods()
    {
        return array(
            XmlGateway::SEPA_METHOD => 'SEPA',
            XmlGateway::CREDITCARD_METHOD => 'Creditcard',
            XmlGateway::ONLINE_TRANSFER_METHOD => 'Online Transfer (Sofort)',
            XmlGateway::PAYPAL_METHOD => 'PayPal',
            XmlGateway::IDEAL_METHOD => 'iDEAL',
            XmlGateway::EPS_METHOD => 'eps',
            XmlGateway::GIROPAY_METHOD => 'giropay',
        );
    }

    public function setPaymentMethod($value)
    {
        return $this->setParameter('paymentMethod', $value);
    }

    public function getPaymentMethod()
    {
        return $this->getParameter('paymentMethod');
    }


    public function getReturnMethod()
    {
        return $this->getParameter('returnMethod');
    }

    public function setReturnMethod($value)
    {
        return $this->setParameter('returnMethod', $value);
    }

    public function getCancelMethod()
    {
        return $this->getParameter('cancelMethod');
    }

    public function setCancelMethod($value)
    {
        return $this->setParameter('cancelMethod', $value);
    }

    public function getChosenOnly()
    {
        return $this->getParameter('chosenOnly');
    }

    public function setChosenOnly($value)
    {
        return $this->setParameter('chosenOnly', $value);
    }

    protected function validateCard($parameters = array())
    {
        $card = $this->getCard();
        foreach ($parameters as $parameter) {
            $value = $card->{'get' . ucfirst($parameter)}();
            if (!isset($value)) {
                throw new InvalidRequestException("The $parameter parameter is required");
            }
        }
    }

    public function shouldRedirect()
    {
        return true;
    }

    public function encode($data, $password)
    {
        $data = trim($data);
        if ($data == '') {
            return 'Error: no data';
        }
        if (!function_exists('base64_encode') or !function_exists('pack') or !function_exists('crc32')) {
            return 'Error: func n/a';
        }
        try {
            $crc = sprintf('%u', crc32($data));# %u is a must for ccrc32 returns a signed value
            $data = $crc . "|" . $data;
            $data = bin2hex($data . $password);
            $data = strrev(base64_encode($data));
        } catch (Exception $e) {
            echo('Error: ' . $e);
        }

        return $data;
    }

    protected function hash1($h, $key) #$h contains encoded data
    {
        if (!$h) {
            return 'Error: no data';
        }
        if (!function_exists('md5')) {
            return 'Error: func n/a';
        }

        return md5(
            $h['auth_code'] .
            $h['product_id'] .
            $h['tariff'] .
            $h['amount'] .
            $h['test_mode'] .
            $h['uniqid'] .
            strrev($key)
        );
    }

    protected function encodeParams($auth_code, $product_id, $tariff_id, $amount, $test_mode, $uniqid, $password)
    {
        $auth_code = self::encode($auth_code, $password);
        $product_id = self::encode($product_id, $password);
        $tariff_id = self::encode($tariff_id, $password);
        $amount = self::encode($amount, $password);
        $test_mode = self::encode($test_mode, $password);
        $uniqid = self::encode($uniqid, $password);
        $hash = self::hash1(array(
            'auth_code' => $auth_code,
            'product_id' => $product_id,
            'tariff' => $tariff_id,
            'amount' => $amount,
            'test_mode' => $test_mode,
            'uniqid' => $uniqid,
        ), $password);

        return array($auth_code, $product_id, $tariff_id, $amount, $test_mode, $uniqid, $hash);
    }

    public function shouldEncode()
    {
        return true;
    }
}