<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once('stripe-php-1.18.0/Stripe.php');

/**
 * Define gateway configuration options.
 *
 * The fields you define here determine the configuration options that are
 * presented to administrator users when activating and configuring your
 * payment gateway module for use.
 *
 * Supported field types include:
 * * text
 * * password
 * * yesno
 * * dropdown
 * * radio
 * * textarea
 *
 * Examples of each field type and their possible configuration parameters are
 * provided in the sample function below.
 *
 * @return array
 */
function stripe_alipay_config()
{
    return array(
        // the friendly display name for a payment gateway should be
        // defined here for backwards compatibility
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Stripe Alipay',
        ),
        // a text field type allows for single line text input
        'sk_test' => array(
            'FriendlyName' => 'Test Secret Key',
            'Type' => 'text',
            'Size' => '32',
            'Default' => '',
        ),
        'pk_test' => array(
            'FriendlyName' => 'Test Publishable Key',
            'Type' => 'text',
            'Size' => '32',
            'Default' => '',
        ),
        'sk_live' => array(
            'FriendlyName' => 'Live Secret Key',
            'Type' => 'text',
            'Size' => '32',
            'Default' => '',
        ),
        'pk_live' => array(
            'FriendlyName' => 'Live Publishable Key',
            'Type' => 'text',
            'Size' => '32',
            'Default' => '',
        ),
        // the yesno field type displays a single checkbox option
        'force_email' => array(
            'FriendlyName' => 'Force Email',
            'Type' => 'yesno',
            'Description' => 'Forcing users to use account email',
        ),
        'remember_me' => array(
            'FriendlyName' => 'Remember Me',
            'Type' => 'yesno',
            'Description' => 'Specify whether to include the option to "Remember Me" for future purchases',
        ),
        'test_mode' => array(
            'FriendlyName' => 'Test Mode',
            'Type' => 'yesno',
            'Description' => 'Tick to enable test mode',
        ),
    );
}

/**
 * Payment link.
 *
 * Required by third party payment gateway modules only.
 *
 * Defines the HTML output displayed on an invoice. Typically consists of an
 * HTML form that will take the user to the payment gateway endpoint.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see http://docs.whmcs.com/Payment_Gateway_Module_Parameters
 *
 * @return string
 */
function stripe_alipay_link($params)
{

    // Gateway Configuration Parameters
    if ($params['test_mode']) {
        $s_key = $params['sk_test'];
        $p_key = $params['pk_test'];
    } else {
        $s_key = $params['sk_live'];
        $p_key = $params['pk_live'];
    }

    // Invoice Parameters
    $invoiceId = $params['invoiceid'];
    $description = $params["description"];
    $amount = $params['amount'];
    $currencyCode = $params['currency'];

    if ($currencyCode == 'USD') {
        $amount = $amount * 100; // cent
    } else {
        $amount = 0; // doesn't support other currency
    }

    // Client Parameters
    $firstname = $params['clientdetails']['firstname'];
    $lastname = $params['clientdetails']['lastname'];
    $email = $params['clientdetails']['email'];
    $address1 = $params['clientdetails']['address1'];
    $address2 = $params['clientdetails']['address2'];
    $city = $params['clientdetails']['city'];
    $state = $params['clientdetails']['state'];
    $postcode = $params['clientdetails']['postcode'];
    $country = $params['clientdetails']['country'];
    $phone = $params['clientdetails']['phonenumber'];

    // System Parameters
    $companyName = $params['companyname'];
    $systemUrl = $params['systemurl'];
    $returnUrl = $params['returnurl'];
    $langPayNow = $params['langpaynow'];
    $moduleDisplayName = $params['name'];
    $moduleName = $params['paymentmethod'];
    $whmcsVersion = $params['whmcsVersion'];

    if ($_POST) {

        $token = $_POST['stripeToken'];

        Stripe::setApiKey($s_key);

        try {

            if (in_array(substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2), array('zh', 'cn'))) {
                $p = '处理中……';
            } else {
                $p = 'Processing...';
            }

            $payload = array(
                "amount" => $amount,
                "currency" => strtolower($currencyCode),
                "source" => $token, // obtained with Stripe.js
                "description" => $description
            );

            $charge = Stripe_Charge::create($payload);
            logModuleCall($moduleDisplayName, 'Stripe_Charge::create', $payload, $charge, $charge, array());

            $transactionStatus = $charge->paid ? 'Success' : 'Failure';

            /**
             * Validate Callback Invoice ID.
             *
             * Checks invoice ID is a valid invoice number. Note it will count an
             * invoice in any status as valid.
             *
             * Performs a die upon encountering an invalid Invoice ID.
             *
             * Returns a normalised invoice ID.
             */
            $invoiceId = checkCbInvoiceID($invoiceId, $moduleDisplayName);

            /**
             * Check Callback Transaction ID.
             *
             * Performs a check for any existing transactions with the same given
             * transaction number.
             *
             * Performs a die upon encountering a duplicate.
             */
            checkCbTransID($charge->id);

            /**
             * Log Transaction.
             *
             * Add an entry to the Gateway Log for debugging purposes.
             *
             * The debug data can be a string or an array. In the case of an
             * array it will be
             *
             * @param string $gatewayName Display label
             * @param string|array $debugData Data to log
             * @param string $transactionStatus Status
             */
            $transaction = Stripe_BalanceTransaction::retrieve($charge->balance_transaction);
            logTransaction($moduleDisplayName, $transaction, $transactionStatus);

            if ($charge->paid) {
                /**
                 * Add Invoice Payment.
                 *
                 * Applies a payment transaction entry to the given invoice ID.
                 *
                 * @param int $invoiceId Invoice ID
                 * @param string $transactionId Transaction ID
                 * @param float $paymentAmount Amount paid (defaults to full balance)
                 * @param float $paymentFee Payment fee (optional)
                 * @param string $gatewayModule Gateway module name
                 */
                addInvoicePayment(
                    $invoiceId,
                    $charge->id,
                    $transaction->amount / 100, //cent
                    $transaction->fee / 100, //cent
                    basename(__FILE__, '.php')
                );
            }

        } catch (Stripe_CardError $e) {
            logTransaction($moduleDisplayName, $e, 'Stripe_CardError');
            $p = 'The card was declined.';
        } catch (Exception $e) {
            logTransaction($moduleDisplayName, $e, 'Exception');
            $p = 'Something else happened.';
        }

        $htmlOutput = '<p>' . $p . '</p>
        <script>
        setTimeout(function(){ location.reload(true); }, 3000);
        </script>';

    } else {

        if ($params['force_email']) {
            $force_email = '    data-email="' . $email . '"';
        } else {
            $force_email = '';
        }

        if ($params['remember_me']) {
            $remember_me = '    data-allow-remember-me="true"';
        } else {
            $remember_me = '    data-allow-remember-me="false"';
        }

        $htmlOutput = '<form action="' . $returnUrl . '" method="POST">
  <script
    src="https://checkout.stripe.com/checkout.js" class="stripe-button"
    data-key="' . $p_key . '"
    data-name="' . $companyName . '"
    data-description="' . $description . '"
    data-amount="' . $amount . '"
    data-label="' . $langPayNow . '"
' . $force_email . '
' . $remember_me . '
    data-currency="' . strtolower($currencyCode) . '"
    data-locale="auto"
    data-alipay="true">
  </script>
</form>';

    }

    return $htmlOutput;
}

/**
 * Refund transaction.
 *
 * Called when a refund is requested for a previously successful transaction.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see http://docs.whmcs.com/Payment_Gateway_Module_Parameters
 *
 * @return array Transaction response status
 */
function stripe_alipay_refund($params)
{

    // Gateway Configuration Parameters
    if ($params['test_mode']) {
        $s_key = $params['sk_test'];
    } else {
        $s_key = $params['sk_live'];
    }

    // Transaction Parameters
    $transactionIdToRefund = $params['transid'];
    $refundAmount = $params['amount'];
    $currencyCode = $params['currency'];

    if (substr($transactionIdToRefund, 0, 2) == 'py') { // Alipay doesn't refund processing fee
        $refundAmount = round($refundAmount - ($refundAmount * 0.029 + 0.3), 2);
    }

    if ($currencyCode == 'USD') {
        $refundAmount = $refundAmount * 100; // cent
    } else {
        $refundAmount = 0; // doesn't support other currency
    }

    // Client Parameters
    $firstname = $params['clientdetails']['firstname'];
    $lastname = $params['clientdetails']['lastname'];
    $email = $params['clientdetails']['email'];
    $address1 = $params['clientdetails']['address1'];
    $address2 = $params['clientdetails']['address2'];
    $city = $params['clientdetails']['city'];
    $state = $params['clientdetails']['state'];
    $postcode = $params['clientdetails']['postcode'];
    $country = $params['clientdetails']['country'];
    $phone = $params['clientdetails']['phonenumber'];

    // System Parameters
    $companyName = $params['companyname'];
    $systemUrl = $params['systemurl'];
    $langPayNow = $params['langpaynow'];
    $moduleDisplayName = $params['name'];
    $moduleName = $params['paymentmethod'];
    $whmcsVersion = $params['whmcsVersion'];

    // perform API call to initiate refund and interpret result
    Stripe::setApiKey($s_key);

    try {

        $charge = Stripe_Charge::retrieve($transactionIdToRefund);
        $payload = array('amount' => $refundAmount);
        $re = $charge->refunds->create($payload);
        logModuleCall($moduleDisplayName, 'Stripe_Refund::create', $payload, $re, $re, array());

        $transaction = Stripe_BalanceTransaction::retrieve($re->balance_transaction);

        return array(
            // 'success' if successful, otherwise 'declined', 'error' for failure
            'status' => 'success',
            // Data to be recorded in the gateway log - can be a string or array
            'rawdata' => $transaction,
            // Unique Transaction ID for the refund transaction
            'transid' => $re->id,
        );
    } catch (Exception $e) {
        return array(
            // 'success' if successful, otherwise 'declined', 'error' for failure
            'status' => 'error',
            // Data to be recorded in the gateway log - can be a string or array
            'rawdata' => $e,
        );
    }
}
