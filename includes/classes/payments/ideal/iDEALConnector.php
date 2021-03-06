<?php

/******************************************************************************
 * History:
 * $Log$
 *
 ******************************************************************************
 * Last CheckIn :   $Author$
 * Date :           $Date$
 * Revision :       $Revision$
 ******************************************************************************
 */

// import the necessary classes and configuration
require_once("AcquirerStatusResponse.php");
require_once("AcquirerTransactionResponse.php");
require_once("DirectoryResponse.php");
require_once("ErrorResponse.php");
require_once("IssuerEntry.php");
require_once("iDEALConnector_config.inc.php");

/**
 * Definition of global constants.
 * Can be used but should not be modified by merchant
 */
define( 'IDEAL_TX_STATUS_INVALID', 		0x00 );
define( 'IDEAL_TX_STATUS_SUCCESS', 		0x01 );
define( 'IDEAL_TX_STATUS_CANCELLED', 	0x02 );
define( 'IDEAL_TX_STATUS_EXPIRED', 		0x03 );
define( 'IDEAL_TX_STATUS_FAILURE', 		0x04 );
define( 'IDEAL_TX_STATUS_OPEN', 		0x05 );

define( 'ING_ERROR_INVALID_SIGNATURE', 	"ING1000" );
define( 'ING_ERROR_COULD_NOT_CONNECT', 	"ING1001" );
define( 'ING_ERROR_PRIVKEY_INVALID', 	"ING1002" );
define( 'ING_ERROR_COULD_NOT_SIGN', 	"ING1003" );
define( 'ING_ERROR_CERTIFICATE_INVALID',"ING1004" );
define( 'ING_ERROR_COULD_NOT_VERIFY',	"ING1005" );
define( 'ING_ERROR_MISSING_CONFIG',		"ING1006" );
define( 'ING_ERROR_PARAMETER',			"ING1007" );
define( 'ING_ERROR_INVALID_SIGNCERT',	"ING1008" );

/**
 * Definition of private constants
 */
define( 'IDEAL_PRV_GENERIEKE_FOUTMELDING', 	"Betalen met IDEAL is nu niet mogelijk. Probeer het later nogmaals of betaal op een andere manier." );
define( 'IDEAL_PRV_STATUS_FOUTMELDING', 	"Het resultaat van uw betaling is nog niet bij ons bekend. U kunt desgewenst uw betaling controleren in uw Internetbankieren." );
define( 'TRACE_DEBUG', 	"DEBUG" );
define( 'TRACE_ERROR', 	"ERROR" );

$iDEALConnector_error = 0;
$iDEALConnector_errstr = "";

function iDEALConnector_error_handler($errno, $errstr)
{
	$iDEALConnector_error = $errno;
	$iDEALConnector_errstr = $errstr;
}

/**
 *  This class is responsible for handling all iDEAL operations and shields
 *  external developers from the complexities of the platform.
 *
 *  PHP 4 does not support visibility modifiers, so public/private/protected cannot
 *  be used.
 */
class iDEALConnector {

	// An object that maintains error information for each request
	var $error;

	// Configuration parameters
	var $config;
	var $verbosity;

	/**
	 * Constructor
	 *
	 * @return iDEALConnector
	 */
	function iDEALConnector($config=null)
	{
		$this->config = (empty($config))?$this->loadConfig():$config;
		$result = true;
		$this->verbosity = $this->getConfiguration( "TRACELEVEL", true, $result );
	}

	/**
	 * Public function to get the list of issuers that the consumer can choose from.
	 *
	 * @return An instance of DirectoryResponse or "FALSE" on failure.
	 */
	function GetIssuerList()
	{
		$this->clearError();

		$configCheck = $this->CheckConfig($this->config);

		if ($configCheck != "OK")
		{
		
			$errorResponse = new ErrorResponse();
				
			$errorResponse->setErrorCode("001");
			$errorResponse->setErrorMessage("Config error: ".$configCheck);
			$errorResponse->setConsumerMessage("");
				
			return $errorResponse;
		}

		// Build up the XML header for this request
		$xmlMsg = $this->getXMLHeader(
        	"DirectoryReq", 
		null,
		null,
		null,
		null );
			
		if ( ! $xmlMsg ) {
			return false;
		}

		// Close the request information.
		$xmlMsg .= "</DirectoryReq>\n";

		// Post the XML to the server.

		$response = $this->PostXMLData( $xmlMsg );
		// If the response did not work out, return an ErrorResponse object.

		 
		if ($this->parseFromXml( "errorCode", $response ) != "")
		{
			$errorResponse = new ErrorResponse();
				
			$errorResponse->setErrorCode($this->parseFromXml( "errorCode", $response ));
			$errorResponse->setErrorMessage($this->parseFromXml( "errorMessage", $response ));
			$errorResponse->setConsumerMessage($this->parseFromXml( "consumerMessage", $response ));
				
			return $errorResponse;
		}
		if ($this->parseFromXml( "acquirerID", $response ) == "")
		{
			$errorResponse = new ErrorResponse();
				
			$errorResponse->setErrorCode("ING1001");
			$errorResponse->setErrorMessage("DirectoryList service probleem");
			$errorResponse->setConsumerMessage("");
				
			return $errorResponse;
		}
		
		// Create a new DirectoryResponse object with the required information
		$res = new DirectoryResponse();
		$res->setAcquirerID( $this->parseFromXml( "acquirerID", $response ) );
		$res->setDirectoryDateTimeStamp( $this->parseFromXml( "directoryDateTimeStamp", $response ) );

		// While there are issuers to be read from the stream
		while ( strpos( $response, "<issuerID>" ) )
		{
			// Read the information for the next issuer.
			$issuerID = $this->parseFromXml( "issuerID", $response );
			$issuerName = $this->parseFromXml( "issuerName", $response );
			$issuerList = $this->parseFromXml( "issuerList", $response );

			// Create a new entry and add it to the list
			$issuerEntry = new IssuerEntry();
			$issuerEntry->setIssuerID( $issuerID );
			$issuerEntry->setIssuerName( $issuerName );
			$issuerEntry->setIssuerListType( $issuerList );
			$res->addIssuer( $issuerEntry );
				
			// Find the next issuer.
			$response = substr( $response, strpos( $response, "</issuerList>" ) + 13 );
		}

		return $res;
	}

	/**
	 * This function submits a transaction request to the server.
	 *
	 * @param string $issuerId			The issuer Id to send the request to
	 * @param string $purchaseId		The purchase Id that the merchant generates
	 * @param integer $amount			The amount in cents for the purchase
	 * @param string $description		The description of the transaction
	 * @param string $entranceCode		The entrance code for the visitor of the merchant site. Determined by merchant
	 * @param string $optExpirationPeriod		Expiration period in specific format. See reference guide. Can be configured in config.
	 * @param string $optMerchantReturnURL		The return URL (optional) for the visitor. Optional. Can be configured in config.
	 * @return An instance of AcquirerTransactionResponse or "false" on failure.
	 */
	function RequestTransaction(
	$issuerId,
	$purchaseId,
	$amount,
	$description,
	$entranceCode,
	$optExpirationPeriod="",	// Optional
	$optMerchantReturnURL="" ) // Optional
	{
		$this->clearError();

		$configCheck = $this->CheckConfig($this->config);

		if ($configCheck != "OK")
		{
			$this->setError( ING_ERROR_MISSING_CONFIG, "Config error: ".$configCheck, IDEAL_PRV_GENERIEKE_FOUTMELDING );
				
			return $this->getError();
		}

		if ( ! $this->verifyNotNull( $issuerId, 	"issuerId" ) ||
		! $this->verifyNotNull( $purchaseId, 	"purchaseId" ) ||
		! $this->verifyNotNull( $amount, 		"amount" ) ||
		! $this->verifyNotNull( $description, 	"description" ) ||
		! $this->verifyNotNull( $entranceCode,	"entranceCode" ) )
		{
			$errorResponse = $this->getError();
				
			return $errorResponse;
		}
		
		//check amount length
		$amountOK = $this->LengthCheck("Amount", $amount, "12");
		if ($amountOK != "ok")
		{
			return $this->getError();
		}
		//check for diacritical characters
		$amountOK = $this->CheckDiacritical("Amount", $amount);
		if ($amountOK != "ok")
		{
			return $this->getError();
		}
		
		//check description length
		$descriptionOK = $this->LengthCheck("Description", $description, "32");
		if ($descriptionOK != "ok")
		{
			return $this->getError();
		}
		//check for diacritical characters
		$descriptionOK = $this->CheckDiacritical("Description", $description);
		if ($descriptionOK != "ok")
		{
			return $this->getError();
		}

		//check entrancecode length
		$entranceCodeOK = $this->LengthCheck("Entrancecode", $entranceCode, "40");
		if ($entranceCodeOK != "ok")
		{
			return $this->getError();
		}
		//check for diacritical characters
		$entranceCodeOK = $this->CheckDiacritical("Entrancecode", $entranceCode);
		if ($entranceCodeOK != "ok")
		{
			return $this->getError();
		}

		//check purchaseid length
		$purchaseIDOK = $this->LengthCheck("PurchaseID", $purchaseId, "16");
		if ($purchaseIDOK != "ok")
		{
			return $this->getError();
		}
		//check for diacritical characters
		$purchaseIDOK = $this->CheckDiacritical("PurchaseID", $purchaseId);
		if ($purchaseIDOK != "ok")
		{
			return $this->getError();
		}

		// According to the specification, these values should be hardcoded.
		$currency = "EUR";
		$language = "nl";

		$result = true;

		// Retrieve these values from the configuration file.
		$cfgExpirationPeriod = $this->getConfiguration( "EXPIRATIONPERIOD", true, $result );
		$cfgMerchantReturnURL = $this->getConfiguration( "MERCHANTRETURNURL", true, $result );

		if ( isset( $optExpirationPeriod ) && ( $optExpirationPeriod != "" ) )
		{
			// If a (valid?) optional setting was specified for the expiration period, use it.
			$expirationPeriod = $optExpirationPeriod;
		}
		else
		{
			$expirationPeriod = $cfgExpirationPeriod;
		}

		if ( isset( $optMerchantReturnURL ) && ( $optMerchantReturnURL != "" ) )
		{
			// If a (valid?) optional setting was specified for the merchantReturnURL, use it.
			$merchantReturnURL = $optMerchantReturnURL;
		}
		else
		{
			$merchantReturnURL = $cfgMerchantReturnURL;
		}

		if ( ! $this->verifyNotNull( $expirationPeriod,	"expirationPeriod" ) ||
		! $this->verifyNotNull( $merchantReturnURL,"merchantReturnURL" ) )
		{
			return false;
		}

		// Build the XML header for the transaction request
		$xmlMsg = $this->getXMLHeader(
        	"AcquirerTrxReq", 
		$issuerId,
			"<Issuer>\n<issuerID>" . $issuerId . "</issuerID>\n</Issuer>\n",
		$merchantReturnURL . $purchaseId . $amount . $currency . $language . $description . $entranceCode,
			"<merchantReturnURL>" . $merchantReturnURL . "</merchantReturnURL>\n" );
			
		if ( ! $xmlMsg ) {
			return false;
		}

		// Add transaction information to the request.
		$xmlMsg .= "<Transaction>\n<purchaseID>" . $purchaseId . "</purchaseID>\n";
		$xmlMsg .= "<amount>" . $amount . "</amount>\n";
		$xmlMsg .= "<currency>" . $currency . "</currency>\n";
		$xmlMsg .= "<expirationPeriod>" . $expirationPeriod . "</expirationPeriod>\n";
		$xmlMsg .= "<language>" . $language . "</language>\n";
		$xmlMsg .= "<description>" . $description . "</description>\n";
		$xmlMsg .= "<entranceCode>" . $entranceCode . "</entranceCode>\n";
		$xmlMsg .= "</Transaction>\n";
		$xmlMsg .= "</AcquirerTrxReq>\n";

		// Post the request to the server.
		$response = $this->PostXMLData( $xmlMsg );

		if ($this->parseFromXml( "errorCode", $response ) != "")
		{
			$errorResponse = new ErrorResponse();
				
			$errorResponse->setErrorCode($this->parseFromXml( "errorCode", $response ));
			$errorResponse->setErrorMessage($this->parseFromXml( "errorMessage", $response ));
			$errorResponse->setConsumerMessage($this->parseFromXml( "consumerMessage", $response ));
				
			return $errorResponse;
		}
		if ($this->parseFromXml( "acquirerID", $response ) == "")
		{
			$errorResponse = new ErrorResponse();
				
			$errorResponse->setErrorCode("ING1001");
			$errorResponse->setErrorMessage("Transactie mislukt (aquirer side)");
			$errorResponse->setConsumerMessage("");
				
			return $errorResponse;
		}

		// Build the transaction response object and pass in the data.
		$res = new AcquirerTransactionResponse();
		$res->setAcquirerID( $this->parseFromXml( "acquirerID", $response ) );
		$res->setIssuerAuthenticationURL( html_entity_decode( $this->parseFromXml( "issuerAuthenticationURL", $response ) ) );
		$res->setTransactionID( $this->parseFromXml( "transactionID", $response ) );
		$res->setPurchaseID( $this->parseFromXml( "purchaseID", $response ) );

		if (!$res)
		{
			return $response;
		}

		return $res;
	}

	/**
	 * This public function makes a transaction status request
	 *
	 * @param string $transactionId	The transaction ID to query. (as returned from the TX request)
	 * @return An instance of AcquirerStatusResponse or FALSE on failure.
	 */
	function RequestTransactionStatus( $transactionId )
	{
		$this->clearError();

		$configCheck = $this->CheckConfig($this->config);

		if ($configCheck != "OK")
		{
			$errorResponse = new ErrorResponse();
				
			$errorResponse->setErrorCode("001");
			$errorResponse->setErrorMessage("Config error: ".$configCheck);
			$errorResponse->setConsumerMessage("");
				
			return $errorResponse;
		}

		//check TransactionId length
		$transactionIdOK = $this->LengthCheck("TransactionID", $transactionId, "16");
		if ($transactionIdOK != "ok")
		{
			return $this->getError();
		}


		if ( ! $this->verifyNotNull( $transactionId, "transactionId" ) )
		{
			$errorResponse = $this->getError();

			return $errorResponse;
		}
		 
		// Build the status request XML.
		$xmlMsg = $this->getXMLHeader(
        	"AcquirerStatusReq", 
		null,
		null,
		$transactionId,
		null );

		if ( ! $xmlMsg ) {
			return false;
		}

		// Add transaction information.
		$xmlMsg .= "<Transaction>\n<transactionID>" . $transactionId . "</transactionID></Transaction>\n";
		$xmlMsg .= "</AcquirerStatusReq>\n";

		// Post the request to the server.
		$response = $this->PostXMLData( $xmlMsg );

		if ($this->parseFromXml( "errorCode", $response ) != "")
		{
			$errorResponse = new ErrorResponse();
				
			$errorResponse->setErrorCode($this->parseFromXml( "errorCode", $response ));
			$errorResponse->setErrorMessage($this->parseFromXml( "errorMessage", $response ));
			$errorResponse->setConsumerMessage($this->parseFromXml( "consumerMessage", $response ));
				
			return $errorResponse;
		}
		if ( ($this->parseFromXml( "acquirerID", $response ) == "") || (!$response ))
		{
			$errorResponse = new ErrorResponse();
				
			$errorResponse->setErrorCode("ING1001");
			$errorResponse->setErrorMessage("Status lookup mislukt (aquirer side)");
			$errorResponse->setConsumerMessage("");
				
			return $errorResponse;
		}
		

		// Build the status response object and pass the data into it.
		$res = new AcquirerStatusResponse();
		$creationTime = $this->parseFromXml( "createDateTimeStamp", $response );
		$res->setAcquirerID( $this->parseFromXml( "acquirerID", $response ) );
		$res->setConsumerName( $this->parseFromXml( "consumerName", $response ) );
		$res->setConsumerAccountNumber( $this->parseFromXml( "consumerAccountNumber", $response ) );
		$res->setConsumerCity( $this->parseFromXml( "consumerCity", $response ) );
		$res->setTransactionID( $this->parseFromXml( "transactionID", $response ) );
		$status = $this->parseFromXml( "status", $response );
    $res->setStatusText($status);

		// The initial status is INVALID, so that future modifications to
		// this or remote code will yield alarming conditions.
		// Determine status identifier (case-insensitive).
		if ( strcasecmp( $status, "success" ) == 0 ) {
			$res->setStatus( IDEAL_TX_STATUS_SUCCESS );
		} else if ( strcasecmp( $status, "Cancelled" ) == 0 ) {
			$res->setStatus( IDEAL_TX_STATUS_CANCELLED );
		} else if ( strcasecmp( $status, "Expired" ) == 0 ) {
			$res->setStatus( IDEAL_TX_STATUS_EXPIRED );
		} else if ( strcasecmp( $status, "Failure" ) == 0 ) {
			$res->setStatus( IDEAL_TX_STATUS_FAILURE );
		} else if ( strcasecmp( $status, "Open" ) == 0 ) {
			$res->setStatus( IDEAL_TX_STATUS_OPEN );
		} else {
   		$res->setStatus( TX_STATUS_INVALID );
    }


		// The verification of the response starts here.
		// The message as per the reference guide instructions.
		$message = $creationTime . $res->getTransactionID() . $status . $res->getConsumerAccountNumber();
		$message = $this->strip( $message );

		// The signature value in the response contains the signed hash
		// (signed by the signing key on the server)
		$signature64 = $this->ParseFromXml( "signatureValue", $response );

		// The signed hash is base64 encoded and inserted into the XML as such
		$sig = base64_decode( $signature64 );

		// The fingerprint is used as the identifier of the public key certificate.
		// It is sent as part of the response XML.
		$fingerprint = $this->ParseFromXml( "fingerprint", $response );

		// The merchant should have the public certificate stored locally.
		$certfile = $this->getCertificateFileName( $fingerprint );
		if ( ! $certfile )
		{
			return false;
		}

		// Verify the message signature
		$valid = $this->verifyMessage( $certfile, $message, $sig );
		if ( ! $valid )
		{
			return false;
		}

		if (!$res)
		{
			return $response;
		}

		return $res;
	}

	/**
	 * This public function returns the ErrorResponse object or "" if it does not exist.
	 *
	 * @return ErrorResponse object or an emptry string "".
	 */
	function getError()
	{
		return $this->error;
	}


	/**************************************************************************
	 * ========================================================================
	 * 					Private functions
	 * ========================================================================
	 *************************************************************************/

	/**
	 * Logs a message to the file.
	 *
	 * @param string $$desiredVerbosity	The desired verbosity of the message
	 * @param string $message			The message to log
	 */
	function log( $desiredVerbosity, $message )
	{
		// Check if the log file is set. If not set, don't log.
		if ( !isset( $this->config["LOGFILE"] ) || ($this->config[ "LOGFILE" ] == "") )
		{
			return;
		}

		if ( strpos( $this->verbosity, $desiredVerbosity ) === false ) {
			// The desired verbosity is not listed in the configuration
			return;
		}

		// Open the log file in 'append' mode.
		$file = fopen( $this->config[ "LOGFILE" ], 'a' );
		fputs( $file, $this->getCurrentDateTime() . ": " );
		fputs( $file, strtoupper( $desiredVerbosity ) . ": " );
		fputs( $file, $message, strlen( $message ) );
		fputs( $file, "\r\n" );
		fclose( $file );
	}

	/**
	 * Creates a new ErrorResponse object and populates it with the arguments
	 *
	 * @param unknown_type $errCode		The error code to return. This is either a code from the platform or an internal code.
	 * @param unknown_type $errMsg		The error message. This is not meant for display to the consumer.
	 * @param unknown_type $consumerMsg	The consumer message. The error message to be shown to the user.
	 */
	function setError( $errCode, $errMsg, $consumerMsg )
	{
		$this->error = new ErrorResponse();
		$this->error->setErrorCode( $errCode );
		$this->error->setErrorMessage( $errMsg );
		if ( $consumerMsg ) {
			$this->error->setConsumerMessage( $consumerMsg );
		} else {
			$this->error->setConsumerMessage( IDEAL_PRV_GENERIEKE_FOUTMELDING );
		}
	}

	/**
	 * Clears the error conditions.
	 */
	function clearError()
	{
		$iDEALConnector_error = 0;
		$iDEALConnector_errstr = "";
		$this->error = "";
	}

	/**
	 * Builds up the XML message header.
	 *
	 * @param string $msgType				The type of message to construct.
	 * @param string $firstCustomIdInsert	The identifier value(s) to prepend to the hash ID.
	 * @param string $firstCustomFragment	The fragment to insert in the header before the general part.
	 * @param string $secondCustomIdInsert	The identifier value(s) to append to the hash ID.
	 * @param string $secondCustomFragment	THe XML fragment to append to the header after the general part.
	 * @return string
	 */
	function getXMLHeader(
	$msgType,
	$firstCustomIdInsert,
	$firstCustomFragment,
	$secondCustomIdInsert,
	$secondCustomFragment )
	{
		// Determine the (string) timestamp for the header and hash id.
		$timestamp = $this->getCurrentDateTime();

		$result = true;

		// Merchant ID and sub ID come from the configuration file.
		$merchantId = utf8_encode( $this->getConfiguration( "MERCHANTID", false, $result ) );
		$subId = utf8_encode( $this->getConfiguration( "SUBID", false, $result ) );

		if ( ! $result ) {
			return false;
		}

		// Build the hash ID
		$message = $this->strip( $timestamp . $firstCustomIdInsert . $merchantId . $subId . $secondCustomIdInsert );

		// Create the certificate fingerprint used to sign the message. This is passed in to identify
		// the public key of the merchant and is used for authentication and integrity checks.
		$privateCert = $this->getConfiguration( "PRIVATECERT", false, $result );
		if ( ! $result ) {
			return false;
		}

		$token = $this->createCertFingerprint( $privateCert );

		if ( ! $token ) {
			return false;
		}

		// Calculate the base-64'd hash of the hashId and store it in tokenCode.
		$tokenCode = $this->calculateHash( $message );

		if ( ! $tokenCode ) {
			return false;
		}

		// Start building the header.
		$xmlHeader = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
		. "<" . $msgType . " xmlns=\"http://www.idealdesk.com/Message\" version=\"1.1.0\">\n"
		. "<createDateTimeStamp>" . $timestamp . "</createDateTimeStamp>\n";

		if ( $firstCustomFragment )
		{
			if ( $firstCustomFragment != "" )
			{
				// If there is a custom fragment to prepend, insert it here.
				$xmlHeader .= $firstCustomFragment . "\n";
			}
		}

		// The general parts of the header
		$xmlHeader .= "<Merchant>\n"
		. "<merchantID>" . $this->encode_html( $merchantId ) . "</merchantID>\n"
		. "<subID>" . $subId . "</subID>\n"
		. "<authentication>SHA1_RSA</authentication>\n"
		. "<token>" . utf8_encode( $token ) . "</token>\n"
		. "<tokenCode>" . utf8_encode( $tokenCode ) . "</tokenCode>\n";

		if ( $secondCustomFragment )
		{
			if ( $secondCustomFragment != "" )
			{
				// If there is a fragment to append, append it here.
				$xmlHeader .= $secondCustomFragment;
			}
		}
		// Close the header and return it.
		$xmlHeader .= "</Merchant>\n";

		return $xmlHeader;
	}

	/**
	 * Strips whitespace from a string.
	 *
	 * @param string $message	The string to strip.
	 * @return string			The stripped string.
	 */
	function strip( $message )
	{
		$message = str_replace( " ", "", $message );
		$message = str_replace( "\t", "", $message );
		$message = str_replace( "\n", "", $message );
		return $message;
	}

	/**
	 * Encodes HTML entity codes to characters
	 *
	 * @param string $text	The text to encode
	 * @return string 		The encoded text
	 */
	function encode_html($text)
	{
		$trans = array ("&amp;" => "&", "&quot;" => "\"", "&#039;" => "'", "&lt;" => "<", "&gt;" => ">");
		return htmlspecialchars(strtr($text, $trans), ENT_QUOTES);
	}

	/**
	 * Gets current date and time.
	 *
	 * @return string	Current date and time.
	 */
	function getCurrentDateTime()
	{
		return utf8_encode( gmdate( 'Y-m-d\TH:i:s.000\Z' ) );
	}

	/**
	 * Loads the configuration for the MPI interface
	 *
	 * @return array().  An array of the configuration elements
	 */
	function loadConfig()
	{
		$conf_data = array();
		$file = fopen( SECURE_PATH . "/config.conf", 'r' );

		// Check if the file exists and read until the end.
		if ( $file )
		{
			while ( ! feof( $file ) )
			{
				$buffer = fgets( $file );
				$buffer = trim( $buffer );

				if ( ! empty( $buffer ) )
				{
					// Separate at the equals-sign.
					$pos = strpos( $buffer, '=' );
					if ( $pos > 0 )
					{
						$dumb = trim( substr( $buffer, 0, $pos ) );
						if ( ! empty( $dumb ) )
						{
							// Populate the configuration array
							$conf_data[ strtoupper( substr($buffer, 0 , $pos) ) ] = substr( $buffer, $pos +1 );
						}
					}
				}
			}
		}

		fclose( $file );

		//view loaded config
		//echo "<pre>";
		//print_r($conf_data);
		//echo "</pre>";

		return $conf_data;
	}

	/**
	 * Checks if the Configuration is set correctly. If an option is not set correctly, it will return an error. This has
	 * to be checked in the begin of every function that needs these settings and if an error occurs, it must rethrown
	 * to show it to the user.
	 *
	 * @return string	Error message when configsetting is missing, if no errors occur, ok is thrown back
	 */
	function CheckConfig($conf_data)
	{
		if ($conf_data['MERCHANTID'] == "")
		{
			return "MERCHANTID ontbreekt!";
		}
		elseif(strlen($conf_data['MERCHANTID']) > 9)
		{
			return "MERCHANTID too long!";
		}
		elseif ($conf_data['SUBID'] == "")
		{
			return "SUBID ontbreekt!";
		}
		elseif(strlen($conf_data['SUBID']) > 6)
		{
			return "SUBID too long!";
		}
		elseif ($conf_data['ACQUIRERURL'] == "")
		{
			return "ACQUIRERURL ontbreekt!";
		}
		elseif ($conf_data['SUBID'] == "")
		{
			return "SUBID ontbreekt!";
		}
		elseif ($conf_data['MERCHANTRETURNURL'] == "")
		{
			return "MERCHANTRETURNURL ontbreekt!";
		}
		elseif(strlen($conf_data['MERCHANTRETURNURL']) > 512)
		{
			return "MERCHANTRETURNURL too long!";
		}
		elseif ($conf_data['EXPIRATIONPERIOD'] == "")
		{
			return "EXPIRATIONPERIOD ontbreekt!";
		}
		else
		{
			return "OK";
		}
	}

	/**
	 * Safely get a configuration item.
	 * Returns the value when $name was found, otherwise an emptry string ("").
	 * If "allowMissing" is set to true, it does not generate an error.
	 *
	 * @param string	$name		The name of the configuration item.
	 * @return string	The value as specified in the configuration file.
	 */
	function getConfiguration( $name, $allowMissing, &$result )
	{
		if ( isset( $this->config[ $name ] ) && ( $this->config[ $name ] != "" ) )
		{
			return $this->config[ $name ];
		}
		if ( $allowMissing )
		{
			return "";
		}
		$this->log( TRACE_ERROR, "The configuration item [" . $name . "] is not configured in the configuration file." );
		$this->setError( ING_ERROR_MISSING_CONFIG, "Missing configuration: " . $name, IDEAL_PRV_GENERIEKE_FOUTMELDING );
		$result = false;
		return false;
	}

	/**
	 * Calculates the hash of a piece and encodes it with base64.
	 *
	 * @param string $message	The message to sign.
	 * @return string			The signature of the message in base64.
	 */
	function calculateHash( $message )
	{
		$result = true;
		// Find keys and sign the message
		$tokenCode = $this->signMessage(
		$this->getConfiguration( "PRIVATEKEY", false, $result ),
		$this->getConfiguration( "PRIVATEKEYPASS", false, $result ),
		$message );

		if ( ! $result ) {
			return false;
		}

		// encode the signature with base64
		$tokenCode = base64_encode( $tokenCode );
		return $tokenCode;
	}

	/**
	 * Create a certificate fingerprint
	 *
	 * @param string $filename	File containing the certificate
	 * @return string	A hex string of the certificate fingerprint
	 */
	function createCertFingerprint($filename)
	{

		// Find the certificate with the given path
		$fullPath = SECURE_PATH . "/" . $filename;
		// Open the certificate file for reading
		$fp = fopen( $fullPath, "r" );

		if ( ! $fp )
		{
			$this->log( TRACE_ERROR, "Could not read certificate [" . $fullPath . "]. It may be invalid." );
			$this->setError( ING_ERROR_CERTIFICATE_INVALID, "Could not read certificate", IDEAL_PRV_GENERIEKE_FOUTMELDING );
			return false;
		}

		// Read in the certificate, then convert to X.509-style certificate
		// and export it for later use.
		$cert = fread( $fp, 8192 );
		fclose( $fp );

		$data = openssl_x509_read( $cert );

		if ( ! $data ) {
			$this->log( TRACE_ERROR, "Could not read certificate [" . $fullPath . "]. It may be invalid." );
			$this->setError( ING_ERROR_CERTIFICATE_INVALID, "Could not read certificate", IDEAL_PRV_GENERIEKE_FOUTMELDING );
			return false;
		}

		if ( ! openssl_x509_export( $data, $data ) )
		{
			$this->log( TRACE_ERROR, "Could not export certificate [" . $fullPath . "]. It may be invalid." );
			$this->setError( ING_ERROR_CERTIFICATE_INVALID, "Could not export certificate", IDEAL_PRV_GENERIEKE_FOUTMELDING );
			return false;
		}

		// Remove any ASCII armor
		$data = str_replace( "-----BEGIN CERTIFICATE-----", "", $data );
		$data = str_replace( "-----END CERTIFICATE-----", "", $data );

		// Decode the public key.
		$data = base64_decode( $data );
		// Digest the binary public key with SHA-1.
		$fingerprint = sha1( $data );

		// Ensure all hexadecimal letters are uppercase.
		$fingerprint = strtoupper( $fingerprint );

		return $fingerprint;
	}

	/**
	 * Creates an SHA-1 digest of a message and signs the digest with
	 * an RSA private key. The result is the signature.
	 *
	 * @param string 	$priv_keyfile	The file containing the private key
	 * @param string 	$key_pass		The password required for the decryption of the key
	 * @param string 	$data			The data to digest
	 * @return string	The signature produced or "false" in case of error.
	 */
	function signMessage($priv_keyfile, $key_pass, $data)
	{
		// Disregard all whitespace
		$data = preg_replace( "/\s/", "", $data );
		 
		// Open private key, decrypt if necessary and get the private key elements.
		$fp = fopen( SECURE_PATH . "/" . $priv_keyfile , "r" );
		if ( !$fp ) {
			$this->log( TRACE_ERROR, "Private key [" . SECURE_PATH . "/" . $priv_keyfile . "] could not be found: " . $errstr );
			$this->setError(
			ING_ERROR_PRIVKEY_INVALID,
        		"Could not find private key.", 
			IDEAL_PRV_STATUS_FOUTMELDING );
			return false;
		}
		$priv_key = fread( $fp, 8192 );
		fclose( $fp );
		$pkeyid = openssl_get_privatekey( $priv_key, $key_pass );

		if ( ! $pkeyid ) {
			$this->log( TRACE_ERROR, "Private key [" . SECURE_PATH . "/" . $priv_keyfile . "] could not be extracted and may be invalid, or the password is incorrect." );
			$this->setError( ING_ERROR_PRIVKEY_INVALID, "Could not extract private key", IDEAL_PRV_GENERIEKE_FOUTMELDING );
			return false;
		}

		// Signing with OpenSSL first digests the data, then signs the digest
		if ( ! openssl_sign( $data, $signature, $pkeyid ) ) {
			$this->log( TRACE_ERROR, "Could not sign message using private key [" . SECURE_PATH . "/" . $priv_keyfile . "]." );
			$this->setError( ING_ERROR_COULD_NOT_SIGN, "Could not sign message", IDEAL_PRV_GENERIEKE_FOUTMELDING );
			return false;
		}

		// free the key from memory
		openssl_free_key( $pkeyid );
		return $signature;
	}

	/**
	 * Verifies the authenticity and integrity of the message
	 *
	 * @param string $certfile		The location to the public certificate to verify against.
	 * @param string $data			The data to verify. This data should already be binary, not base64.
	 * @param string $signature		The signature claimed to be correct
	 * @return boolean		true if ok, false if not ok. Reason given in ErrorResponse.
	 */
	function verifyMessage( $certfile, $data, $signature )
	{
		// $data and $signature are assumed not to contain the data and the signature
		// (default is incorrect).
		$ok = 0;

		// Read certificate, extract public key and prepare it for use.
		$fp = fopen( SECURE_PATH . "/" . $certfile, "r" );
		if ( ! $fp )
		{
			$this->log( TRACE_ERROR, "The certificate file [" . SECURE_PATH . "/" . $certfile . "] does not exist." );
			$this->setError(
			ING_ERROR_INVALID_SIGNCERT,
	        	"Platform signature could not be verified", 
			IDEAL_PRV_STATUS_FOUTMELDING );
			return false;
		}
		$cert = fread( $fp, 8192 );
		fclose( $fp );
		$pubkeyid = openssl_get_publickey( $cert );
		if ( ! $pubkeyid ) {
			$this->log( TRACE_ERROR, "Public server signing certificate [" . SECURE_PATH . "/" . $certfile . "] is invalid." );
			$this->setError(
			ING_ERROR_INVALID_SIGNCERT,
	        	"Platform server signing certificate is invalid", 
			IDEAL_PRV_STATUS_FOUTMELDING );
			return false;
		}

		// The internal function has two paths of execution:
		// 1. The $data is hashed with SHA-1.
		// 2. The $signature is decrypted with the public key.
		//
		// Both paths are compared against each other and verified if equal.
		$ok = openssl_verify( $data, $signature, $pubkeyid );

		// Free the key from memory
		openssl_free_key( $pubkeyid );

		if ( $ok == 1 )
		{
			// -1 = error, 0 = false, 1 = ok.
			return true;
		}

		$this->log( TRACE_ERROR, "The validity of the server message could not be determined." );
		$this->setError(
		ING_ERROR_INVALID_SIGNATURE,
        	"Platform signature invalid", 
		IDEAL_PRV_STATUS_FOUTMELDING );

		return false;
	}

	/**
	 * Gets a valid certificate file name based on the certificate fingerprint.
	 * Uses configuration items in the config file, which are incremented when new
	 * security certificates are issued:
	 * certificate0=ideal1.crt
	 * certificate1=ideal2.crt
	 * etc...
	 *
	 * @param string $fingerprint	A hexadecimal representation of a certificate's fingerprint
	 * @return string	The filename containing the certificate corresponding to the fingerprint
	 */
	function getCertificateFileName( $fingerprint )
	{
		$count = 0;
		$result = true;

		// Don't care whether it exists, that is checked later.
		$certFilename = $this->getConfiguration( "CERTIFICATE" . $count, true, $result );

		// Check if the configuration file contains such an item
		while ( isset($certFilename) )
		{
			// Find the certificate with the given path
			$fullPath = SECURE_PATH . "/" . $certFilename;
					
			if (!isset( $fullPath ))
			{
				print $fullPath;
				// No more certificates left to be verified.
				break;
			}
			
			// Generate a fingerprint from the certificate in the file.
			$buff = $this->createCertFingerprint( $certFilename );
			if ( $buff == false )
			{
				// Could not create fingerprint from configured certificate.
				return false;
			}

			// Check if the fingerprint is equal to the desired one.
			if ( $fingerprint == $buff )
			{
				return $certFilename;
			}

			// Start looking for next certificate
			$count += 1;
			$certFilename = $this->getConfiguration( "CERTIFICATE" . $count, true, $result );
		}

		$this->log( TRACE_ERROR, "Could not find certificate with fingerprint [" . $fingerprint . "]" );
		$this->setError( ING_ERROR_COULD_NOT_VERIFY, "Could not verify message", IDEAL_PRV_GENERIEKE_FOUTMELDING );

		// By default, report no success.
		return false;
	}

	/**
	 * Posts XML data to the server or proxy.
	 *
	 * @param string $msg	The message to post.
	 * @return string		The response of the server.
	 */
	function PostXMLData( $msg )
	{
		$result = true;
		if ( $this->getConfiguration( "PROXY", true, $result ) == "" )
		{
			$acquirerUrl = $this->getConfiguration( "ACQUIRERURL", false, $result );
			
			if ( ! $result ) {
				return false;
			}
			// If Proxy configuration does not exist
			return $this->PostToHost( $acquirerUrl, $msg );
		}

		$proxy = $this->getConfiguration( "PROXY", false, $result );
		$proxyUrl = $this->getConfiguration( "PROXYACQURL", false, $result );

		if ( ! $result ) {
			return false;
		}

		// if proxy configuration exists
		return $this->PostToHostProxy( $proxy, $proxyUrl, $msg );
	}

	/**
	 * Posts a message to the host.
	 *
	 * @param string $url	The URL to send the message to.
	 * @param string $data_to_send	The data to send
	 * @return string	The response from the server.
	 */
	function PostToHost( $url, $data_to_send )
	{
		// Decompose the URL into specific parts.
		$idx = strrpos( $url, ":" );
		$host = substr( $url, 0, $idx );
		$url = substr( $url, $idx + 1 );
		$idx = strpos( $url, "/" );
		$port = substr( $url, 0, $idx );
		$path = substr( $url, $idx);
	//	$port = 443;

	//	$host = "https://idealtest.secure-ing.com/ideal/iDeal";
		
		// Log the request
		$this->log( TRACE_DEBUG, "sending to " . $host . ":" . $port . $path . ": " . $data_to_send );

		// Post to the server
		return $this->PostToServer( $host, $port, $path, $data_to_send );
	}

	/**
	 * Posts to a proxy, which is slightly different
	 *
	 * @param string $proxy		The proxy to post to
	 * @param string $url		The URL the proxy should post to.
	 * @param string $data_to_send	The data to send
	 * @return string	The response
	 */
	function PostToHostProxy( $proxy, $url, $data_to_send )
	{
		// Decompose the proxy url
		$idx = strrpos( $proxy, ":" );
		$host = substr( $proxy, 0, $idx );
		$idx = strpos( $proxy, ":" );
		$port = substr( $proxy, $idx + 1 );
		
		 
		// Log the request
		$this->log( TRACE_DEBUG, "sending through proxy " . $host . ":" . $port . ": " . $data_to_send );

		// Post to the proxy
		return $this->PostToServer( $host, $port, $url, $data_to_send );
	}

	/**
	 * Posts to the server and interprets the result
	 *
	 * @param string $host		The host to post to
	 * @param string $port		The port to use
	 * @param string $path		The application path on the remote server
	 * @param string $data_to_send	The data to send
	 * @return string	The response of the remote server.
	 */
	function PostToServer( $host, $port, $path, $data_to_send )
	{
		$res = "";
		 
		$result = true;
		 
		// The connection timeout for the remote server.
		$timeout = $this->getConfiguration( "ACQUIRERTIMEOUT", false, $result );
		if ( ! $result ) {
			return false;
		}

		// Open connection and report problems in custom error handler.

		$fsp = fsockopen( $host, $port, $errno, $errstr, $timeout );
		
		
		
		if ( $fsp )
		{
			// Succeeded with the connection. POST information.
			fwrite( $fsp, "POST $path HTTP/1.0\r\n" );
			fwrite( $fsp, "Accept: text/html\r\n" );
			fwrite( $fsp, "Accept: charset=ISO-8859-1\r\n" );
			fwrite( $fsp, "Content-Length: " . strlen( $data_to_send ) . "\r\n" );
			fwrite( $fsp, "Content-Type: text/html; charset=ISO-8859-1\r\n\r\n" );
			fwrite( $fsp, $data_to_send, strlen( $data_to_send ) );

			// Read the response from the server, until the very end
			while( ! feof( $fsp ) )
			{
				$res .= fgets( $fsp, 128 );
			}
			fclose( $fsp );

			// Log the response
			$this->log( TRACE_DEBUG, "receiving from " . $host . ":" . $port . $path . ": " . $res );

			if ( $this->parseFromXml( "ErrorRes", $res ) )
			{
				// If the response was an error message, parse it and return an error.
				$this->setError(
				$this->parseFromXml( "errorCode", $res ),
				$this->parseFromXml( "errorMessage", $res ),
				$this->parseFromXml( "consumerMessage", $res ) );

				return $this->getError();
			}

			// Return the (textual) response
			return $res;
		}
		else
		{
			// An error occurred when trying to connect to the server.
			$this->log( TRACE_ERROR, "Could not connect to: [" . $host . ":" . $port . $path . "]" );
			$this->setError( ING_ERROR_COULD_NOT_CONNECT, "Could not connect to remote server", IDEAL_PRV_GENERIEKE_FOUTMELDING );
			return false;
		}
	}

	/**
	 * Function to parse XML
	 *
	 * @param string $key	The XML tag to look for.
	 * @param string $xml	The XML string to look into.
	 * @return string	The value (PCDATA) inbetween the tag.
	 */
	function parseFromXml( $key, $xml )
	{
		$begin = 0;
		$end = 0;

		// Find the first occurrence of the tag
		$begin = strpos( $xml, "<" . $key . ">" );
		if ( $begin === false )
		{
			return false;
		}

		// Find the end position of the tag.
		$begin += strlen( $key ) + 2;
		$end = strpos( $xml, "</" . $key . ">" );

		if ( $end === false )
		{
			return false;
		}

		// Get the value inbetween the tags and replace the &amp; character.
		$result = substr( $xml, $begin, $end - $begin );
		$result = str_replace( "&amp;", "&", $result );

		// Decode it with UTF-8
		return utf8_decode( $result );
	}

	/**
	 * Verifies if the parameter is not empty.
	 *
	 * @param string $parameter
	 * @param string $paramName
	 * @return
	 */
	function verifyNotNull( $paramValue, $paramName )
	{
		if (( ! isset( $paramValue ) ) || ( $paramValue == "" )) {
			$this->log( TRACE_ERROR, "The parameter [" . $paramName . "] should have a value." );
			$this->setError( ING_ERROR_PARAMETER, "Empty parameter not allowed: " . $paramName, IDEAL_PRV_GENERIEKE_FOUTMELDING );
			return false;
		}
		return true;
	}

	/**
	 * Verifies if the parameter is not too long.
	 *
	 * @param string $checkName
	 * @param string $checkVariable
	 * @param string $checkLength
	 *
	 * @return ErrorResponse object when failed, string when succeeded
	 */
	function LengthCheck($checkName, $checkVariable, $checkLength)
	{
		if (strlen($checkVariable) > $checkLength)
		{
			$this->setError( ING_ERROR_PARAMETER, $checkName." too long", IDEAL_PRV_GENERIEKE_FOUTMELDING );

			return "NotOk";
		}
		else
		{
			return "ok";
		}
	}

	/**
	 * Checks if the inserted variable ($checkVariable) contains diacritical characters.
	 * If so, it will return an ErrorResponse object. If not, the string "ok" is returned.
	 *
	 * @param string $checkName
	 * @param string $checkVariable
	 *
	 * @return ErrorResponse object when failed, string when succeeded
	 */
	function CheckDiacritical($checkName, $checkVariable)
	{
		//$pattern = "/^[A-Za-z0-9\=\ \%\*\+\,\.\/\/\&\@\"\'\:\;\?\(\)\$]/";
		$pattern = "/[�����������������������������]/";
		
		//echo "pattern= ".$pattern."<br />";
		//echo $checkName."= ".$checkVariable."<br />";
		
		if (preg_match($pattern, $checkVariable, $matches))
		{
			$this->setError( ING_ERROR_PARAMETER, $checkName." contains diacritical or non-permitted character(s)", IDEAL_PRV_GENERIEKE_FOUTMELDING );

			return "NotOk";
		}
		else
		{
			return "ok";
		}
	}
}
?>