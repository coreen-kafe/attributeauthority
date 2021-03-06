<?php

/**
 * Implements SAML2 Attribute Authority
 */
class sspmod_aa_AA_SAML2
{
    private $binding;
    private $query;
    private $aaEntityId;
    private $aaMetadata;
    private $spEntityId;
    private $spMetadata;
    private $config;
    private $attributeNameFormat;
    private $signAssertion;
    private $signResponse;

    public function __construct($metadata)
    {
        $this->config = SimpleSAML_Configuration::getConfig('module_aa.php');

        $this->signAssertion = false;
        if ($this->config->hasValue('signAssertion')) {
            $this->signAssertion = $this->config->getBoolean('signAssertion');
        }

        $this->signResponse = true;
        if ($this->config->hasValue('signResponse')) {
            $this->signResponse = $this->config->getBoolean('signResponse');
        }

        $this->sslClientCertContainer = 'SSL_CLIENT_CERT';
        if ($this->config->hasValue('sslClientCertContainer')) {
            $this->sslClientCertContainer = $this->config->getString('sslClientCertContainer');
        }

        $this->binding = $this->getBinding();
        $this->query = $this->getQuery();
        $this->attributeNameFormat = $this->getAttributeNameFormat();
        $this->getEntities($metadata);
    }

    public function getBinding()
    {
        /* Receiving the attribute query */
        $binding = SAML2_Binding::getCurrentBinding();
	SimpleSAML\Logger::info('[aa]: '. var_export($binding, true));

        /* Supported binding is SOAP */
        if (!($binding instanceof \SAML2\SOAP)) { 
            throw new SimpleSAML_Error_BadRequest('[aa] Unsupported binding. It must be SAML2_SOAP.');
        }

        SimpleSAML\Logger::debug('[aa] binding: '.var_export($binding, true));

        return $binding;
    }

    private function getQuery()
    {
        $query = $this->binding->receive();
        SimpleSAML\Logger::debug('[aa] query: '.var_export($query, true));

        if (!($query instanceof \SAML2\AttributeQuery)) {
            throw new SimpleSAML_Error_BadRequest('Invalid message received on AttributeQuery endpoint.');
        }

        return $query;
    }

    private function getEntities($metadata)
    {
        /* Getting the related entities metadata objects */
        $aaEntityId = $metadata->getMetaDataCurrentEntityID('attributeauthority-hosted');
        $aaMetadata = $metadata->getMetadataConfig($aaEntityId, 'attributeauthority-hosted');

        $spEntityId = $this->query->getIssuer();
        if ($spEntityId === null) {
            throw new SimpleSAML_Error_BadRequest('Missing <saml:Issuer> in <samlp:AttributeQuery>.');
        }
        $spMetadata = $metadata->getMetaDataConfig($spEntityId, 'saml20-sp-remote');

        $this->aaEntityId = $aaEntityId;
        $this->aaMetadata = $aaMetadata;
        $this->spEntityId = $spEntityId;
        $this->spMetadata = $spMetadata;
        
        SimpleSAML\Logger::info('[aaentityid] '.$aaEntityId);
        //SimpleSAML\Logger::info($aaMetadata);
        SimpleSAML\Logger::info('[spentityid] '.$spEntityId);
        //SimpleSAML\Logger::info($spMetadata);
    }

    private function getAttributeNameFormat()
    {
        /* The name format of the attributes. */
        //$attributeNameFormat = SAML2_Const::NAMEFORMAT_URI;
        $attributeNameFormat = 'urn:oasis:names:tc:SAML:2.0:attrname-format:uri';
        if ($this->config->hasValue('attributeNameFormat')) {
            $attributeNameFormat = $this->config->getValue('attributeNameFormat');
        }

        return $attributeNameFormat;
    }

    public function handleAttributeQuery()
    {
        // Authenticate the requestor
        $this->authenticate();

        // Get all attributes from the auth sources
        $attributes = $this->getAttributes();

        // Filter attributes by AA filters
        $this->processFilters($attributes);

        // Filter attributes by SP
        $this->filterFromRequest($attributes);

        // Build the whole response
        $response = $this->buildResponse($attributes);

        // Send the response
        $this->sendResponse($response);
    }

    private function authenticate()
    {
        $client_is_authenticated = false;

        /* Authenticate the requestor by verifying the TLS certificate used for the HTTP query */
        if (array_key_exists('SSL_CLIENT_VERIFY', $_SERVER)) {
            SimpleSAML\Logger::debug('[aa] Request was made using the following certificate: '.var_export($_SERVER['SSL_CLIENT_VERIFY'], 1));
        }
        if (array_key_exists('SSL_CLIENT_VERIFY', $_SERVER)
            && $_SERVER['SSL_CLIENT_VERIFY']
            && $_SERVER['SSL_CLIENT_VERIFY'] != 'NONE'
            && $_SERVER[$this->sslClientCertContainer]
            ) {
            /* compare certificate fingerprints */
            $clientCertData = trim(preg_replace('/--.* CERTIFICATE-+-/', '', $_SERVER[$this->sslClientCertContainer]));
            $clientCertFingerprint = strtolower(sha1(base64_decode($clientCertData)));
            if (!$clientCertFingerprint) {
                throw new SimpleSAML_Error_Exception('[aa] Can not calculate certificate fingerprint from the request.');
            }

            $spCertArray = SimpleSAML_Utilities::loadPublicKey($this->spMetadata);
            if (!$spCertArray) {
                throw new SimpleSAML_Error_Exception('[aa] Can not find the public key of the requestor in the metadata!');
            }

            foreach ($spCertArray['certFingerprint'] as $fingerprint) {
                if ($fingerprint && $clientCertFingerprint == $fingerprint) {
                    $client_is_authenticated = true;
                    SimpleSAML\Logger::debug('[aa] SSL certificate is checked and valid.');
                    break;
                }
            }
            /* Reject the request if the TLS certificate used for the request does not match metadata */
            if (!$client_is_authenticated) {
                throw new SimpleSAML_Error_Exception('[aa] SSL certificate check failed.');
            }
        } else {
            /* The request may be signed, so this is not fatal */
            SimpleSAML\Logger::debug('[aa] SSL client certificate does not exist.');
        }

        /* Authenticate the requestor by verifying the XML signature on the query */
        $certs_of_query = $this->query->getCertificates();
        if (count($certs_of_query) > 0) {
            if (sspmod_saml_Message::checkSign($this->spMetadata, $this->query)) {
                $client_is_authenticated = true;
                SimpleSAML\Logger::debug('[aa] AttributeQuery signature is checked and valid.');
            } else {
                /* An invalid or unverifiable signature is fatal */
                throw new SimpleSAML_Error_Exception('[aa] The signature of the AttributeQuery is wrong!');
            }
        } else {
            /* The request may be protected by HTTP TLS (X.509) authentication, so this is not fatal */
            SimpleSAML\Logger::debug('[aa] AttributeQuery has no signature.');
        }

        if (!$client_is_authenticated) {
            SimpleSAML\Logger::info('[aa] Attribute query was not authenticated. Drop.');
            header('HTTP/1.1 401 Unauthorized');
            header('WWW-Authenticate: None', false);
            echo 'Not authenticated. Neither query signature nor SSL client certificate was available.';
            exit;
        } else {
            SimpleSAML\Logger::debug('[aa] Attribute query was authenticated.');
        }
    }

    private function getAttributes()
    {
        $nameId = $this->query->getNameId();

        if (!$nameId) {
            throw new SimpleSAML_Error_BadRequest('[aa] Error getting NameID from AttributeQuery.');
        }
        if (array_key_exists('Format', $nameId)) {
            $nameIdFormat = $nameId->Format;
        }

        SimpleSAML\Logger::info('[aa] Received attribute query for '.$nameId->value.' (nameIdFormat: '.$nameIdFormat.')');

        /* Get the attributes from the AuthSource */
        $spMetadataArray = $this->spMetadata->toArray();
        $aaMetadataArray = $this->aaMetadata->toArray();
        $attributes = array();
        $state = array(
            'Attributes' => $attributes,
            'Destination' => $spMetadataArray,
            'Source' => $aaMetadataArray,
            'aa:nameId' => $nameId->value,
            'aa:nameIdFormat' => $nameIdFormat,
        );

        $as = SimpleSAML_Auth_Source::getById($this->config->getValue('authsource'));
        $as->authenticate($state);

        $attributes = $state['Attributes'];

        return $attributes;
    }

    private function processFilters(&$attributes)
    {
        $spMetadataArray = $this->spMetadata->toArray();
        $aaMetadataArray = $this->aaMetadata->toArray();
        $pc = new SimpleSAML_Auth_ProcessingChain($aaMetadataArray, $spMetadataArray, 'aa');
        $authProcState = array(
            'Attributes' => $attributes,
            'Destination' => $spMetadataArray,
            'Source' => $aaMetadataArray,
        );
        $pc->processStatePassive($authProcState); // backend, passive processing, no user interaction
        $attributes = $authProcState['Attributes'];
    }

    private function filterFromRequest(&$attributes)
    {
        $requestedAttributes = $this->query->getAttributes();
        if (count($requestedAttributes) === 0) {
            SimpleSAML\Logger::debug(
                '[aa] No attributes requested - return all previously resolved attributes: '.var_export($attributes, true)
            );
        } elseif ($this->query->getAttributeNameFormat() !== $this->attributeNameFormat) {
            SimpleSAML\Logger::debug(
                '[aa] NameFormat mismatch - no attributes returned. Expected: '.$this->attributeNameFormat.' Requested: '.$this->query->getAttributeNameFormat()
            );
            $attributes = array();
        } else {
            foreach ($attributes as $name => $values) {
                if (!array_key_exists($name, $requestedAttributes)) {
                    /* They didn't request this attribute. */
                    SimpleSAML\Logger::debug('[aa] Remove attribute because it was not requested: '.$name);
                    unset($attributes[$name]);
                    continue;
                }

                if (count($values) === 0) {
                    /* Return all values. */
                    continue;
                }

                /* Filter which attribute values we should return. */
                $attributes[$name] = array_intersect($values, $requestedAttributes[$name]);
            }
        }
    }

    private function buildResponse($returnAttributes)
    {
        /* SubjectConfirmation */
        $sc = new SAML2_XML_saml_SubjectConfirmation();
        $sc->Method = SAML2_Const::CM_BEARER;
        $sc->SubjectConfirmationData = new SAML2_XML_saml_SubjectConfirmationData();
        $sc->SubjectConfirmationData->NotBefore = time();
        $sc->SubjectConfirmationData->NotOnOrAfter = time() + $this->config->getInteger('validFor');
        $sc->SubjectConfirmationData->InResponseTo = $this->query->getId();

        $assertion = new SAML2\Assertion();
        $assertion->setSubjectConfirmation(array($sc));
        $assertion->setIssuer($this->aaEntityId);
        $assertion->setNameId($this->query->getNameId());
        $assertion->setNotBefore(time());
        $assertion->setNotOnOrAfter(time() + $this->config->getInteger('validFor'));
        $assertion->setValidAudiences(array($this->spEntityId));
        $assertion->setAttributes($returnAttributes);
        $assertion->setAttributeNameFormat($this->attributeNameFormat);
        if ($this->signAssertion) {
            sspmod_saml_Message::addSign($this->aaMetadata, $this->spMetadata, $assertion);
        }

        /* The Response */
        //$response = new SAML2\Response($this->query->toUnsignedXML());
        $response = new SAML2\Response();

        $response->setRelayState($this->query->getRelayState());
        $response->setIssuer($this->aaEntityId);
        $response->setDestination($this->query->getId());
        $response->setInResponseTo($this->query->getIssuer());
        $response->setAssertions(array($assertion));
        if ($this->signResponse) {
            sspmod_saml_Message::addSign($this->aaMetadata, $this->spMetadata, $response);
        }

        return $response;
    }

    private function sendResponse($response)
    {
        SimpleSAML\Logger::debug('[aa] Sending: '.var_export($response, true));
        SimpleSAML\Logger::info('[aa] Sending assertion.');

        $this->binding->send($response);
    }
}
