<?php
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once('../includes/autoload.php');
use \Firebase\JWT\JWT;

$gatewayModuleName = basename(__FILE__, '.php');


$gatewayParams = getGatewayVariables($gatewayModuleName);
$secretKey = $gatewayParams['secretKey'];


if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

if (isset($_GET['token'])) {
    try {
        $token = $_GET['token'];
        $decoded = JWT::decode($token, $secretKey, array('HS256'));
        $result = (array) $decoded;

        $status = $result['status'];
        $invoiceId = $result["orderid"];
        $transactionId = $result['id'];
        $operationId = $result['operationid'];

        logTransaction($gatewayModuleName, $result, "Decoded JWT Data");

        if ($status === 'success') {
            $invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

            checkCbTransID($transactionId);

            $invoiceData = localAPI('GetInvoice', array('invoiceid' => $invoiceId));

            if ($invoiceData['result'] == 'success') {
                $paymentAmount = $invoiceData['balance'] > 0 ? $invoiceData['balance'] : $invoiceData['total'];
            } else {
                $invoiceData = full_query("SELECT total, status FROM tblinvoices WHERE id = '" . (int) $invoiceId . "'");
                $invoiceData = mysql_fetch_assoc($invoiceData);
                $paymentAmount = $invoiceData['total'];
            }

            $paymentFee = 0;

            logTransaction($gatewayModuleName, array(
                'invoiceId' => $invoiceId,
                'transactionId' => $transactionId,
                'operationId' => $operationId,
                'amount' => $paymentAmount,
                'fee' => $paymentFee,
                'status' => $status
            ), "Payment Processing Details");

            $paymentSuccess = addInvoicePayment(
                $invoiceId,
                $transactionId,
                $paymentAmount,
                $paymentFee,
                $gatewayModuleName
            );

            if ($paymentSuccess) {
                logTransaction($gatewayModuleName, array(
                    'invoiceId' => $invoiceId,
                    'transactionId' => $transactionId,
                    'amount' => $paymentAmount
                ), "Payment Successful");

                $updateQuery = "UPDATE tblinvoices SET status = 'Paid' WHERE id = '" . (int) $invoiceId . "'";
                full_query($updateQuery);

                $systemUrl = rtrim($gatewayParams['systemurl'], '/');
                $returnUrl = $systemUrl . '/viewinvoice.php?id=' . $invoiceId . '&paymentsuccess=true';
                header('Location: ' . $returnUrl);
            } else {
                logTransaction($gatewayModuleName, array(
                    'invoiceId' => $invoiceId,
                    'transactionId' => $transactionId
                ), "Payment Failed - addInvoicePayment returned false");

                $systemUrl = rtrim($gatewayParams['systemurl'], '/');
                header('Location: ' . $systemUrl . '/viewinvoice.php?id=' . $invoiceId . '&paymentfailed=true');
            }
            exit;
        } else if ($status == 'failed') {
            logTransaction($gatewayModuleName, $result, "Payment Failed - Status: failed");
            $systemUrl = rtrim($gatewayParams['systemurl'], '/');
            header('Location: ' . $systemUrl . '/viewinvoice.php?id=' . $invoiceId . '&paymentfailed=true');
            exit;

        } else {
            logTransaction($gatewayModuleName, $result, "Unknown Status Received");
            $systemUrl = rtrim($gatewayParams['systemurl'], '/');
            header('Location: ' . $systemUrl . '/clientarea.php?action=invoices&paymenterror=true');
            exit;
        }

    } catch (Exception $e) {
        logTransaction($gatewayModuleName, array(
            'error' => $e->getMessage(),
            'token' => $_GET['token'],
            'request' => $_REQUEST
        ), "JWT Decoding Error");

        die("Error processing payment: " . $e->getMessage());
    }

} else {
    logTransaction($gatewayModuleName, $_REQUEST, "No Token Received");
    die("No token received");
}
