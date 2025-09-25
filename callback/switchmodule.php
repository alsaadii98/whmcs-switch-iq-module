<?php
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModuleName = 'switchmodule';

// Fetch gateway configuration parameters
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

// Get parameters
$invoiceId = isset($_GET['invoiceid']) ? $_GET['invoiceid'] : '';
$resourcePath = isset($_GET['resourcePath']) ? $_GET['resourcePath'] : '';
$id = isset($_GET['id']) ? $_GET['id'] : '';

// Log incoming request for debugging
logTransaction($gatewayModuleName, $_GET, "Callback Received");

if (empty($invoiceId)) {
    logTransaction($gatewayModuleName, $_GET, "Missing invoice ID in callback");
    die("Invalid request - missing invoice ID");
}

// Validate invoice ID
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

// Get payment status from Switch API
$isProduction = $gatewayParams['isProduction'];
$isProductionLink = $isProduction ? 'https://eu-prod.oppwa.com' : 'https://eu-test.oppwa.com';
$entityId = $gatewayParams['EntityID'];
$token = $gatewayParams['Token'];

// Determine which API endpoint to use
if (!empty($resourcePath)) {
    $requestURL = $isProductionLink . $resourcePath . '?entityId=' . $entityId;
} elseif (!empty($id)) {
    $requestURL = $isProductionLink . '/v1/checkouts/' . $id . '/payment?entityId=' . $entityId;
} else {
    logTransaction($gatewayModuleName, $_GET, "Missing both resourcePath and id parameters");
    die("Invalid request parameters");
}

$requestHeader = array(
    'Authorization: Bearer ' . $token,
);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $requestURL);
curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeader);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$responseData = curl_exec($ch);

if (curl_errno($ch)) {
    $curlError = curl_error($ch);
    logTransaction($gatewayModuleName, array('error' => $curlError, 'url' => $requestURL), "CURL Error");
    die('Curl error: ' . $curlError);
}
curl_close($ch);

$responseData = json_decode($responseData, true);

// Log the response for debugging
logTransaction($gatewayModuleName, $responseData, "Payment Status Response");

// Determine transaction status
$successCodes = array('000.000.000', '000.100.110', '000.100.111', '000.100.112');
$resultCode = isset($responseData['result']['code']) ? $responseData['result']['code'] : '';

if (in_array($resultCode, $successCodes)) {
    // Successful payment
    $transactionId = isset($responseData['id']) ? $responseData['id'] : '';
    $paymentAmount = isset($responseData['amount']) ? $responseData['amount'] : '';

    // Validate transaction ID
    checkCbTransID($transactionId);

    // Get invoice details
    try {
        $invoiceData = localAPI('GetInvoice', array('invoiceid' => $invoiceId));
        if ($invoiceData['result'] == 'success') {
            $invoiceAmount = $invoiceData['balance'] > 0 ? $invoiceData['balance'] : $invoiceData['total'];
        } else {
            throw new Exception("Local API failed");
        }
    } catch (Exception $e) {
        // Fallback if localAPI fails
        $result = full_query("SELECT total, status FROM tblinvoices WHERE id = '" . (int) $invoiceId . "'");
        if ($result && $invoiceData = mysql_fetch_assoc($result)) {
            $invoiceAmount = $invoiceData['total'];
        } else {
            $invoiceAmount = $paymentAmount;
        }
    }

    $paymentFee = 0;

    // Log before adding payment
    logTransaction($gatewayModuleName, array(
        'invoiceId' => $invoiceId,
        'transactionId' => $transactionId,
        'invoiceAmount' => $invoiceAmount,
        'paymentAmount' => $paymentAmount,
        'resultCode' => $resultCode
    ), "Attempting to add payment");

    // Add payment to invoice
    $paymentSuccess = addInvoicePayment(
        $invoiceId,
        $transactionId,
        $invoiceAmount,
        $paymentFee,
        $gatewayModuleName
    );

    if ($paymentSuccess) {
        logTransaction($gatewayModuleName, array(
            'invoiceId' => $invoiceId,
            'transactionId' => $transactionId,
            'amount' => $invoiceAmount,
            'resultCode' => $resultCode
        ), "Payment Successful - Invoice Updated");

        // Redirect to success page - NO OUTPUT BEFORE THIS!
        $systemUrl = rtrim($gatewayParams['systemurl'], '/');
        header('Location: ' . $systemUrl . '/viewinvoice.php?id=' . $invoiceId . '&paymentsuccess=true');
        exit;
    } else {
        logTransaction($gatewayModuleName, array(
            'invoiceId' => $invoiceId,
            'transactionId' => $transactionId,
            'invoiceAmount' => $invoiceAmount
        ), "Payment Failed - addInvoicePayment returned false");

        $systemUrl = rtrim($gatewayParams['systemurl'], '/');
        header('Location: ' . $systemUrl . '/viewinvoice.php?id=' . $invoiceId . '&paymentfailed=true');
        exit;
    }
} else {
    // Failed payment
    $errorMessage = isset($responseData['result']['description']) ? $responseData['result']['description'] : 'Payment failed';
    logTransaction($gatewayModuleName, array(
        'invoiceId' => $invoiceId,
        'resultCode' => $resultCode,
        'error' => $errorMessage
    ), "Payment Failed");

    $systemUrl = rtrim($gatewayParams['systemurl'], '/');
    header('Location: ' . $systemUrl . '/viewinvoice.php?id=' . $invoiceId . '&paymentfailed=true');
    exit;
}