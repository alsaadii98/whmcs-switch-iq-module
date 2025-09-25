<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function switchmodule_MetaData()
{
    return array(
        'DisplayName' => 'Switch IQ Payment Gateway',
        'APIVersion' => '1.0',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
        'GatewayType' => 'Payments',
    );
}

function switchmodule_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Switch IQ Payment Gateway',
        ),
        'EntityID' => array(
            'FriendlyName' => 'Entity ID',
            'Type' => 'text',
            'Size' => '256',
            'Default' => '',
            'Description' => 'Enter your entity ID here',
        ),
        'Token' => array(
            'FriendlyName' => 'Token',
            'Type' => 'password',
            'Size' => '256',
            'Default' => '',
            'Description' => 'Enter token here',
        ),
        'PaymentType' => array(
            'FriendlyName' => 'Payment Type',
            'Type' => 'dropdown',
            'Options' => array(
                'DB' => 'Debit',
                'PA' => 'Pre-Authorization',
                'CD' => 'Credit',
                'CP' => 'Capture (PA amount)',
                'RV' => 'Reversal',
                'RF' => 'Refund',
            ),
            'Description' => 'Choose one',
        ),
        'Currency' => array(
            'FriendlyName' => 'Currency',
            'Type' => 'dropdown',
            'Options' => array(
                'IQD' => 'IQD',
                'USD' => 'USD',
            ),
            'Description' => 'Choose one',
        ),
        'Integrity' => array(
            'FriendlyName' => 'Integrity',
            'Type' => 'yesno',
            'Description' => 'Tick to enable integrity check',
        ),
        'isProduction' => array(
            'FriendlyName' => 'Is Production',
            'Type' => 'yesno',
            'Description' => 'Tick if this is a production environment',
        ),
    );
}

function switchmodule_link($params)
{
    // Gateway Configuration Parameters

    $entityId = $params['EntityID'];
    $token = $params['Token'];
    $paymentType = $params['PaymentType'];
    $currency = $params['Currency'];
    $integrity = $params['Integrity'];
    $isProduction = $params['isProduction'];

    // Invoice Parameters
    $invoiceId = $params['invoiceid'];
    $description = $params["description"];
    $amount = number_format($params['amount'], 2, '.', '');
    $currencyCode = $params['currency'];

    // System Parameters
    $companyName = $params['companyname'];
    $systemUrl = $params['systemurl'];
    $returnUrl = $params['returnurl'];
    $langPayNow = $params['langpaynow'];
    $moduleDisplayName = $params['name'];
    $whmcsVersion = $params['whmcsVersion'];

    $isProductionLink = $isProduction ? 'https://eu-prod.oppwa.com' : 'https://eu-test.oppwa.com';
    $shopperResultURL = $systemUrl . '/modules/gateways/callback/switchmodule.php?invoiceid=' . $invoiceId;

    if (empty($entityId) || empty($token) || empty($paymentType) || empty($currency)) {
        return '<div class="alert alert-danger">Error: Please configure the module before using it.</div>';
    }

    // Create checkout session
    $requestURL = $isProductionLink . '/v1/checkouts';
    $requestHeader = array(
        'Authorization: Bearer ' . $token,
    );

    $postData = http_build_query(array(
        'entityId' => $entityId,
        'amount' => $amount,
        'currency' => $currency,
        'paymentType' => $paymentType,
        'merchantTransactionId' => $invoiceId,
        'integrity' => $integrity ? 'true' : 'false',
    ));

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $requestURL);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeader);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $responseData = curl_exec($ch);

    if (curl_errno($ch)) {
        return '<div class="alert alert-danger">Curl error: ' . curl_error($ch) . '</div>';
    }
    curl_close($ch);

    $responseData = json_decode($responseData, true);

    if (!isset($responseData['id'])) {
        $errorMessage = isset($responseData['result']['description']) ? $responseData['result']['description'] : 'Unknown error';
        return '<div class="alert alert-danger">Error: Unable to create checkout. ' . $errorMessage . '</div>';
    }

    $checkoutId = $responseData['id'];
    $integrityId = $responseData['ndc'];

    // Generate HTML form
    $htmlOutput = '<form action="' . $shopperResultURL . '" method="POST" class="paymentWidgets" data-brands="VISA MASTER">';
    $htmlOutput .= '</form>';

    $scriptSrc = $isProductionLink . '/v1/paymentWidgets.js?checkoutId=' . $checkoutId;
    if ($integrity && !empty($integrityId)) {
        $scriptSrc .= '" crossorigin="anonymous" integrity="' . $integrityId;
    }

    $htmlOutput .= '<script src="' . $scriptSrc . '"></script>';


    return $htmlOutput;
}