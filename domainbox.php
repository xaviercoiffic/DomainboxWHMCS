<?php

// Classes required for API calls
require_once("domainbox_objects.php");

// Hook support (not used currently)
require_once("domainbox_hooks.php");

/**
 * Gets the configuration for the Domainbox API calls
 * @return array
 */
function domainbox_getConfigArray()
{
    $configArray = array("Reseller" => array("Type" => "text", "Size" => "20", "Description" => "Enter your reseller name here",), "Username" => array("Type" => "text", "Size" => "20", "Description" => "Enter your username here",), "Password" => array("Type" => "password", "Size" => "20", "Description" => "Enter your password here",), "TestMode" => array("Type" => "yesno",),);
    return $configArray;
}

/**
 * Checks each domain marked as active for their current status.
 * @param $params
 * @return array {expirydate => the current expiry date, active => is the domain active, expired => has the domain expired}
 */
function domainbox_Sync($params)
{
    $authParameters = getAuthParameters($params);
    $apiEndpoint = $params["TestMode"] ? "https://sandbox.domainbox.net/?WSDL" : "https://live.domainbox.net/?WSDL";

    $queryDomainDatesParameters = new QueryDomainDatesParameters();
    $queryDomainDatesParameters->DomainName = $params["sld"] . '.' . $params["tld"];

    try
    {
        $parameters = array('AuthenticationParameters' => $authParameters, 'CommandParameters' => $queryDomainDatesParameters);
        $client = new SoapClient($apiEndpoint, array('soap_version' => SOAP_1_2));
        $result = $client->QueryDomainDates($parameters);

        $result = $result->QueryDomainDatesResult;

        if ($result->ResultCode == 100) // Command Successful
        {
            $values["expirydate"] = $result->ExpiryDate;
            $values["active"] = true;
            $values["expired"] = hasDomainExpired($result->ExpiryDate);
            $values['error'] = "";
        }
        elseif ($result->ResultCode == 295) // Domain not in reseller account
        {
            //TODO: Should this mark domain as cancelled?
            $values['active'] = false;
            $values['error'] = 'Domain not found';
        }
        else // Other API error
        {
            $values["error"] = $result->ResultMsg;
        }

    }
    catch (Exception $e)
    {
        $values["error"] = "There was an error communicating with Domainbox";
    }

    return $values;
}

/**
 * Checks the status of any domain marked as pending transfer, if the transfer is completed then domain is marked
 * as active and placed in the correct status, if the domain is marked as transfer cancelled then the domain is marked
 * as such in the WHMCS database
 * @param $params
 * @return array
 */
function domainbox_TransferSync($params)
{
    // Get the domainbox domain ID
    $table = "mod_domainbox";
    $fields = "domainboxDomainID";
    $where = array("whmcsDomainID" => $params['domainid']);
    $result = select_query($table, $fields, $where);
    $data = mysql_fetch_array($result);
    $domainID = $data['domainboxDomainID'];

    // API configuration
    $authParameters = getAuthParameters($params);
    $apiEndpoint = $params["TestMode"] ? "https://sandbox.domainbox.net/?WSDL" : "https://live.domainbox.net/?WSDL";

    // Command parameters
    $queryTransferParameters = new QueryTransferParameters();
    $queryTransferParameters->DomainName = $params["sld"] . '.' . $params["tld"];
    $queryTransferParameters->DomainId = $domainID;

    try
    {
        $parameters = array('AuthenticationParameters' => $authParameters, 'CommandParameters' => $queryTransferParameters);
        $client = new SoapClient($apiEndpoint, array('soap_version' => SOAP_1_2));
        $result = $client->QueryTransfer($parameters);
        $result = $result->QueryTransferResult;

        if ($result->ResultCode == 100)
        {
            switch ($result->TransferStatus)
            {
                case 3: // Transfer Completed
                    // Need to get the correct expiry date for the domain
                    $queryDomainResult = domainbox_Sync($params);
                    $values['expirydate'] = $queryDomainResult['expirydate'];
                    break;
                case 4: // Transfer Cancelled
                    $values['error'] = "Transfer Cancelled for domain " . $queryTransferParameters->DomainName;
                    // Mark domain as cancelled in the database
                    $table = "tbldomains";
                    $update = array("status"=> "Cancelled");
                    $where = array("id" => $params['domainid']);
                    update_query($table, $update, $where);
                    break;
            }

            // Only mark as active if the transfer is completed
            $values["active"] = $result->TransferStatus == 3;

            //TODO: Check if the domain is expired post transfer (possible for UK domains).
            $values["expired"] = false;
        }
        else
        {
            // If the code is not 100 then return the Domainbox error
            $values["error"] = $result->ResultMsg;
        }
    }
    catch (Exception $e)
    {
        $values["error"] = "There was an error communicating with Domainbox";
    }

    return $values;
}

/**
 * Gets the current nameservers assigned to the domain
 * @param $params
 * @return array
 */
function domainbox_GetNameservers($params)
{
    $authParameters = getAuthParameters($params);
    $apiEndpoint = $params["TestMode"] ? "https://sandbox.domainbox.net/?WSDL" : "https://live.domainbox.net/?WSDL";

    $queryDomainNameserversParameters = new QueryDomainNameserversParameters();
    $queryDomainNameserversParameters->DomainName = $params["sld"] . '.' . $params["tld"];

    try
    {
        $parameters = array('AuthenticationParameters' => $authParameters, 'CommandParameters' => $queryDomainNameserversParameters);
        $client = new SoapClient($apiEndpoint, array('soap_version' => SOAP_1_2));
        $result = $client->QueryDomainNameservers($parameters);

        $result = $result->QueryDomainNameserversResult;

        if ($result->ResultCode == 100)
        {
            $nameservers = $result->Nameservers;
            $values["ns1"] = $nameservers->NS1;
            $values["ns2"] = $nameservers->NS2;
            $values["ns3"] = $nameservers->NS3;
            $values["ns4"] = $nameservers->NS4;
            $values["ns5"] = $nameservers->NS5;
        }
        else
        {
            // If the code is not 100 then return the Domainbox error
            $values["error"] = $result->ResultMsg;
        }
    }
    catch (Exception $e)
    {
        $values["error"] = "There was an error communicating with Domainbox";
    }

    return $values;
}

function domainbox_SaveNameservers($params)
{
    $authParameters = getAuthParameters($params);
    $apiEndpoint = $params["TestMode"] ? "https://sandbox.domainbox.net/?WSDL" : "https://live.domainbox.net/?WSDL";

    $wasLocked = queryDomainLock($params);

    if ($wasLocked)
    {
        modifyDomainLock($params, false);
    }

    $modifyDomainNameserversParameters = new ModifyDomainNameserversParameters();
    $modifyDomainNameserversParameters->DomainName = $params["sld"] . '.' . $params["tld"];
    $nameservers = new Nameservers();
    $nameservers->NS1 = $params["ns1"];
    $nameservers->NS2 = $params["ns2"];
    $nameservers->NS3 = $params["ns3"];
    $nameservers->NS4 = $params["ns4"];
    $nameservers->NS5 = $params["ns5"];
    $modifyDomainNameserversParameters->Nameservers = $nameservers;

    try
    {
        $parameters = array('AuthenticationParameters' => $authParameters, 'CommandParameters' => $modifyDomainNameserversParameters);
        $client = new SoapClient($apiEndpoint, array('soap_version' => SOAP_1_2));
        $result = $client->ModifyDomainNameservers($parameters);

        $result = $result->ModifyDomainNameserversResult;

        if ($result->ResultCode <> 100)
        {
            $values["error"] = $result->ResultMsg;
        }
    }
    catch (Exception $e)
    {
        $values["error"] = "There was an error communicating with Domainbox";
    }

    if ($wasLocked)
    {
        modifyDomainLock($params, true);
    }

    return $values;
}

/**
 * Gets the current lock status of the domain
 * @param $params
 * @return string
 */
function domainbox_GetRegistrarLock($params)
{
    $authParameters = getAuthParameters($params);
    $apiEndpoint = $params["TestMode"] ? "https://sandbox.domainbox.net/?WSDL" : "https://live.domainbox.net/?WSDL";

    $queryDomainLockParameters = new QueryDomainLockParameters();
    $queryDomainLockParameters->DomainName = $params["sld"] . '.' . $params["tld"];

    $lock = false;

    try
    {
        $parameters = array('AuthenticationParameters' => $authParameters, 'CommandParameters' => $queryDomainLockParameters);
        $client = new SoapClient($apiEndpoint, array('soap_version' => SOAP_1_2));
        $result = $client->QueryDomainLock($parameters);

        $result = $result->QueryDomainLockResult;

        if ($result->ResultCode == 100)
        {
            $lock = $result->ApplyLock;
        }
        else
        {
            // If the code is not 100 then return the Domainbox error
            $values["error"] = $result->ResultMsg;
        }
    }
    catch (Exception $e)
    {
        $values["error"] = "There was an error communicating with Domainbox";
    }

    return $lock ? 'locked' : 'unlocked';
}

/**
 * Changes the lock status on the domain
 * @param $params
 * @return array
 */
function domainbox_SaveRegistrarLock($params)
{
    $authParameters = getAuthParameters($params);
    $apiEndpoint = $params["TestMode"] ? "https://sandbox.domainbox.net/?WSDL" : "https://live.domainbox.net/?WSDL";

    $modifyDomainLockParameters = new ModifyDomainLockParameters();
    $modifyDomainLockParameters->DomainName = $params["sld"] . '.' . $params["tld"];

    $modifyDomainLockParameters->ApplyLock = $params['lockenabled'] == "locked" ? true : false;

    try
    {
        $parameters = array('AuthenticationParameters' => $authParameters, 'CommandParameters' => $modifyDomainLockParameters);
        $client = new SoapClient($apiEndpoint, array('soap_version' => SOAP_1_2));
        $result = $client->ModifyDomainLock($parameters);

        $result = $result->ModifyDomainLockResult;

        if ($result->ResultCode <> 100)
        {
            $values["error"] = $result->ResultMsg;
        }
    }
    catch (Exception $e)
    {
        $values["error"] = "There was an error communicating with Domainbox";
    }

    return $values;
}

/**
 * Gets the current DNS records when the Domain is using DNS farm nameservers
 * @param $params
 * @return array
 */
function domainbox_GetDNS($params)
{
    $authParameters = getAuthParameters($params);
    $apiEndpoint = $params["TestMode"] ? "https://sandbox.domainbox.net/?WSDL" : "https://live.domainbox.net/?WSDL";

    $queryDnsRecordsParameters = new QueryDnsRecordsParameters();
    $queryDnsRecordsParameters->Zone = $params["sld"] . '.' . $params["tld"];

    $hostRecords = array();

    try
    {
        $parameters = array('AuthenticationParameters' => $authParameters, 'CommandParameters' => $queryDnsRecordsParameters);
        $client = new SoapClient($apiEndpoint, array('soap_version' => SOAP_1_2));
        $result = $client->QueryDnsRecords($parameters);

        $result = $result->QueryDnsRecordsResult;

        if ($result->ResultCode == 100)
        {
            if ($result->TotalResults > 1)
            {
                foreach ($result->Records->DnsRecordQueryResult as $dnsRecord)
                {
                    $hostRecords[] = array('hostname' => $dnsRecord->HostName, 'type' => $dnsRecord->RecordType, 'address' => $dnsRecord->Content, 'priority' => $dnsRecord->Priority);
                }
            }
            else
            {
                foreach ($result->Records as $dnsRecord)
                {
                    $hostRecords[] = array('hostname' => $dnsRecord->HostName, 'type' => $dnsRecord->RecordType, 'address' => $dnsRecord->Content, 'priority' => $dnsRecord->Priority);
                }
            }
        }
    }
    catch (Exception $e)
    {
        //TODO: Find out a way to return error to WHMCS.
    }

    return $hostRecords;
}

function domainbox_SaveDNS($params)
{
    // Get the list of existing DNS records
    $existingRecords = domainbox_GetDNS($params);

    $newRecordList = array();
    $deleteRecords = array();
    $modifyRecords = array();
    $createRecords = array();

    foreach ($params["dnsrecords"] AS $key => $values)
    {
        if (!(strlen($values['address']) < 1 && strlen($values['hostname']) < 1))
        {
            $dnsRecord = new DnsRecordParameter();
            $dnsRecord->HostName = $values["hostname"];
            $dnsRecord->RecordType = $values["type"];
            $dnsRecord->Content = $values["address"];
            $dnsRecord->Priority = $dnsRecord->RecordType == "MX" ? $params["priority"] : 0;
            $newRecordList[] = $dnsRecord;
        }
    }

    foreach ($newRecordList as $newRecord)
    {
        // Check if the record is in the existing records
        $isExistingRecord = false;
        foreach ($existingRecords as $existingRecord)
        {
            if ($newRecord->HostName == $existingRecord['hostname'] && $newRecord->RecordType == $existingRecord['type'])
            {
                $isExistingRecord = true;

                // Is in the existing records, so this is either delete or modify
                if (!($newRecord->Content == $existingRecord['address']))
                {
                    if (strlen($newRecord->Content) < 1)
                    {
                        $newRecord->Content = $existingRecord['address'];
                        $deleteRecords[] = $newRecord;
                    }
                    else
                    {
                        $newRecord->OldContent = $existingRecord['address'];
                        $modifyRecords[] = $newRecord;
                    }
                    break;
                }
            }
        }

        if (!$isExistingRecord)
        {
            $createRecords[] = $newRecord;
        }
    }

    // Domainbox API configuration
    $authParameters = getAuthParameters($params);
    $apiEndpoint = $params["TestMode"] ? "https://sandbox.domainbox.net/?WSDL" : "https://live.domainbox.net/?WSDL";

    if (count($createRecords) > 0)
    {
        // Create DNS records
        $createDnsRecordsParameters = new CreateDnsRecordsParameters();
        $createDnsRecordsParameters->Zone = $params['sld'] . '.' . $params['tld'];

        try
        {
            $createDnsRecordsParameters->Records = $createRecords;

            $parameters = array('AuthenticationParameters' => $authParameters, 'CommandParameters' => $createDnsRecordsParameters);
            $client = new SoapClient($apiEndpoint, array('soap_version' => SOAP_1_2));
            $result = $client->CreateDnsRecords($parameters);

            $result = $result->CreateDnsRecordsResult;

            if ($result->ResultCode <> 100)
            {
                $error = $result->ResultMsg;
            }
        }
        catch (Exception $e)
        {
            $error = "There was an error communicating with Domainbox";
        }
    }

    if (count($deleteRecords) > 0)
    {
        // Delete DNS records
        $deleteDnsRecordsParameters = new DeleteDnsRecordsParameters();
        $deleteDnsRecordsParameters->Zone = $params['sld'] . '.' . $params['tld'];

        try
        {
            $deleteDnsRecordsParameters->Records = $deleteRecords;

            $parameters = array('AuthenticationParameters' => $authParameters, 'CommandParameters' => $deleteDnsRecordsParameters);
            $client = new SoapClient($apiEndpoint, array('soap_version' => SOAP_1_2));
            $result = $client->DeleteDnsRecords($parameters);

            $result = $result->DeleteDnsRecordsResult;

            if ($result->ResultCode <> 100)
            {
                $error = $result->ResultMsg;
            }
        }
        catch (Exception $e)
        {
            $error = "There was an error communicating with Domainbox";
        }
    }

    if (count($modifyRecords) > 0)
    {
        // Modify DNS records
        $modifyDnsRecordsParameter = new ModifyDnsRecordsParameters();
        $modifyDnsRecordsParameter->Zone = $params["sld"] . '.' . $params["tld"];

        try
        {
            $modifyDnsRecordsParameter->Records = $modifyRecords;

            $parameters = array('AuthenticationParameters' => $authParameters, 'CommandParameters' => $modifyDnsRecordsParameter);
            $client = new SoapClient($apiEndpoint, array('soap_version' => SOAP_1_2));
            $result = $client->ModifyDnsRecords($parameters);

            $result = $result->ModifyDnsRecordsResult;

            if ($result->ResultCode <> 100)
            {
                $error = $result->ResultMsg;
            }
        }
        catch (Exception $e)
        {
            $error = "There was an error communicating with Domainbox";
        }
    }

    $values["error"] = $error;

    return $values;
}

function domainbox_RegisterDomain($params)
{
    $tld = $params['tld'];

    $authParameters = getAuthParameters($params);
    $apiEndpoint = $params["TestMode"] ? "https://sandbox.domainbox.net/?WSDL" : "https://live.domainbox.net/?WSDL";
    $registerDomainParameters = new RegisterDomainParameters();
    $registerDomainParameters->DomainName = $params["sld"] . '.' . $params["tld"];
    $registerDomainParameters->Period = $params["regperiod"];
    $registerDomainParameters->ApplyLock = !$params['tld'] == '.co.uk';
    $registerDomainParameters->ApplyPrivacy = false;
    $registerDomainParameters->AutoRenew = false;
    $registerDomainParameters->LaunchPhase = "GA";

    $nameServerParameters = new Nameservers();
    $nameServerParameters->NS1 = $params['ns1'];
    $nameServerParameters->NS2 = $params['ns2'];
    $nameServerParameters->NS3 = $params['ns3'];
    $nameServerParameters->NS4 = $params['ns4'];
    $nameServerParameters->NS5 = $params['ns5'];

    $needsZone = false;

    foreach ($nameServerParameters as $key => $value)
    {
        $needsZone = stripos($value, 'dnsfarm.org') > 0;
        if ($needsZone)
        {
            break;
        }
    }

    $registrant = new Contact();
    $registrant->City = $params['city'];
    $registrant->CountryCode = $params['country'];
    $registrant->Email = $params['email'];
    $registrant->Name = $params['firstname'] . ' ' . $params['lastname'];
    $registrant->Organization = $params['companyname'];
    $registrant->Street1 = $params['address1'];
    $registrant->Street2 = $params['address2'];
    $registrant->State = $params['state'];
    $registrant->Postcode = $params['postcode'];
    $localTelephone = str_replace(' ', '', $params['adminphonenumber']);
     if (substr($localTelephone, 0, 1) == "+")
     {
      $localTelephone = str_replace('.', '', $localTelephone);
     }
     else
     {
      $localTelephone = $phoneCountryCode . "." . $localTelephone;
     }
     $registrant->Telephone = $localTelephone;
    // Work out what additional parameters we need
    switch ($tld)
    {
        case 'co.uk':
        case 'org.uk':
        case 'me.uk':
        case 'uk':
            $registrant->AdditionalData = new AdditionalDataParameter();
            $ukData = new UKAdditionalDataParameter();
            $ukData->CompanyNumber = $params['additionalfields']['Company ID Number'];
            $ukData->mapTypeToDomainbox($params['additionalfields']['Legal Type'], $registrant->CountryCode);
            $registrant->Name = $params['additionalfields']['Registrant Name']; // This will override the contact details
            $ukData->TradingName = ""; //TODO: Allow customer to enter on WHMCS.
            $registerDomainParameters->ApplyPrivacy = $params['additionalfields']['WHOIS Opt-out'];
            $registrant->AdditionalData->UKAdditionalData = $ukData;
            break;

        case 'pro':
            $proData = new ProProfessionDataParameter();
            $proData->Profession = $params['additionalfields']['Profession'];
            $registerDomainParameters->Extension = new ExtensionParameter();
            $registerDomainParameters->Extension->ProProfessionData = $proData;
            break;

        case 'us':
            $usData = new USAdditionalDataParameter();
            $usData->Category = $params['additionalfields']['Nexus Category'];
            $usData->mapTypeToDomainbox($params['additionalfields']['Application Purpose']);
            $registerDomainParameters->ApplyPrivacy = false;
            $registrant->AdditionalData->USAdditionalData = $usData;
            break;

        case 'es':
            $esData = new ESAdditionalDataParameter();
            $esData->ContactType = ''; //TODO: Needs drop down on WHMCS (could make best guess in code..)
            $esData->IdentificationType = $params['additionalfields']['ID Form Type'];
            $esData->IdentificationNumber = $params['additionalfields']['ID Form Number'];
            $registrant->AdditionalData->ESAdditionalData = $esData;
            break;

        case 'it':
            $itData = new ITAdditionalDataParameter();
            $itData->EntityType = $params['additionalfields']['Legal Type'];
            $itData->Nationality = $registrant->CountryCode;
            $itData->RegCode = $params['additionalfields']['Tax ID'];
            $registrant->AdditionalData->ITAdditionalData = $itData;
            break;

        case 'fr':
        case 'yt':
        case 'tf':
        case 'pm':
        case 're':
        case 'wf':
            //TODO: Support Afnic domains.
            $afnicData = new AfnicAdditionalDataParameter();
            $afnicData->BirthCc = '';
            $afnicData->BirthCity = '';
            $afnicData->BirthDate = '';
            $afnicData->BirthPc = '';
            $registrant->AdditionalData->AfnicAdditionalData = $afnicData;
            break;

        case 'nl':
            //TODO: Support SIDN domains.
            $nlData = new NLAdditionalDataParameter();
            $nlData->LegalType = '';
            $nlData->LegalTypeRegistrationNumber = '';
            $registrant->AdditionalData->NLAdditionalData = $nlData;
            break;
    }

    $admin = new Contact();
    $admin->City = $params["admincity"];
    $admin->CountryCode = $params['admincountry'];
    $admin->Email = $params['adminemail'];
    $admin->Name = $params['adminfirstname'] . ' ' . $params['adminlastname'];
    $admin->Organization = $params['admincompanyname'];
    $admin->Street1 = $params['adminaddress1'];
    $admin->Street2 = $params['adminaddress2'];
    $admin->State = $params['adminstate'];
    $admin->Postcode = $params['adminpostcode'];
     $localTelephone = str_replace(' ', '', $params['adminphonenumber']);
     if (substr($localTelephone, 0, 1) == "+")
     {
      $localTelephone = str_replace('.', '', $localTelephone);
     }
     else
     {
      $localTelephone = $phoneCountryCode . "." . $localTelephone;
     }
     $admin->Telephone = $localTelephone;
    

    $billing = new Contact();
    $billing->City = $params["admincity"];
    $billing->CountryCode = $params['admincountry'];
    $billing->Email = $params['adminemail'];
    $billing->Name = $params['adminfirstname'] . ' ' . $params['adminlastname'];
    $billing->Street1 = $params['adminaddress1'];
    $billing->Street2 = $params['adminaddress2'];
    $billing->State = $params['adminstate'];
    $billing->Postcode = $params['adminpostcode'];
    $localTelephone = str_replace(' ', '', $params['adminphonenumber']);
     if (substr($localTelephone, 0, 1) == "+")
     {
     $localTelephone = str_replace('.', '', $localTelephone);
     }
     else
     {
     $localTelephone = $phoneCountryCode . "." . $localTelephone;
     }
    $billing->Telephone = $localTelephone;

    $tech = new Contact();
    $tech->City = $params["admincity"];
    $tech->CountryCode = $params['admincountry'];
    $tech->Email = $params['adminemail'];
    $tech->Name = $params['adminfirstname'] . ' ' . $params['adminlastname'];
    $tech->Street1 = $params['adminaddress1'];
    $tech->Street2 = $params['adminaddress2'];
    $tech->State = $params['adminstate'];
    $tech->Postcode = $params['adminpostcode'];
    $localTelephone = str_replace(' ', '', $params['adminphonenumber']);
     if (substr($localTelephone, 0, 1) == "+")
     {
     $localTelephone = str_replace('.', '', $localTelephone);
     }
     else
     {
     $localTelephone = $phoneCountryCode . "." . $localTelephone;
     }
    $tech->Telephone = $localTelephone;

    $contacts = new Contacts();
    $contacts->Registrant = $registrant;
    $contacts->Admin = $admin;
    $contacts->Billing = $billing;
    $contacts->Tech = $tech;

    $registerDomainParameters->Nameservers = $nameServerParameters;
    $registerDomainParameters->Contacts = $contacts;

    try
    {
        $parameters = array('AuthenticationParameters' => $authParameters, 'CommandParameters' => $registerDomainParameters);
        $client = new SoapClient($apiEndpoint, array('soap_version' => SOAP_1_2));
        $result = $client->RegisterDomain($parameters);

        $result = $result->RegisterDomainResult;

        if ($result->ResultCode == 100)
        {

            if ($needsZone)
            {
                $createDnsZoneParameters = new CreateDnsZoneParameters();
                $createDnsZoneParameters->Zone = $registerDomainParameters->DomainName;
                $parameters = array('AuthenticationParameters' => $authParameters, 'CommandParameters' => $createDnsZoneParameters);
                $client = new SoapClient($apiEndpoint, array('soap_version' => SOAP_1_2));
                $client->CreateDnsZone($parameters);
            }

            $table = "mod_domainbox";
            $values = array("whmcsDomainID"=> $params['domainid'], "domainboxDomainID"=> $result->DomainId);
            insert_query($table, $values);

        }
        else
        {
            $error = $result->ResultMsg;
        }
    }
    catch (Exception $e)
    {
        $error = "There was an error communicating with Domainbox";
    }

    # If error, return the error message in the value below
    $values["error"] = $error;
    return $values;
}

/**
 * Requests a transfer for the specified domain
 * @param $params
 * @return array
 */
function domainbox_TransferDomain($params)
{
    $authParameters = getAuthParameters($params);
    $apiEndpoint = $params["TestMode"] ? "https://sandbox.domainbox.net/?WSDL" : "https://live.domainbox.net/?WSDL";

    $transferDomainParameters = new RequestTransferParameters();
    $transferDomainParameters->DomainName = $params["sld"] . '.' . $params["tld"];

    $nameServerParameters = new Nameservers();
    $nameServerParameters->NS1 = $params['ns1'];
    $nameServerParameters->NS2 = $params['ns2'];
    $nameServerParameters->NS3 = $params['ns3'];
    $nameServerParameters->NS4 = $params['ns4'];
    $nameServerParameters->NS5 = $params['ns5'];

    $registrant = new Contact();
    $registrant->City = $params['city'];
    $registrant->CountryCode = $params['country'];
    $registrant->Email = $params['email'];
    $registrant->Name = $params['firstname'] . ' ' . $params['lastname'];
    $registrant->Organization = $params['companyname'];
    $registrant->Street1 = $params['address1'];
    $registrant->Street2 = $params['address2'];
    $registrant->State = $params['state'];
    $registrant->Postcode = $params['postcode'];
    $localTelephone = str_replace(' ', '', $params['phonenumber']);
    if (substr($localTelephone, 0, 1) == "0")
    {
        $localTelephone = substr($localTelephone, 1);
    }
    $phonePrefix = '+' . $params['phonecc'] . '.';
    $registrant->Telephone = $phonePrefix . $localTelephone;

    $admin = new Contact();
    $admin->City = $params["admincity"];
    $admin->CountryCode = $params['admincountry'];
    $admin->Email = $params['adminemail'];
    $admin->Name = $params['adminfirstname'] . ' ' . $params['adminlastname'];
    $admin->Organization = $params['admincompanyname'];
    $admin->Street1 = $params['adminaddress1'];
    $admin->Street2 = $params['adminaddress2'];
    $admin->State = $params['adminstate'];
    $admin->Postcode = $params['adminpostcode'];
    $localTelephone = $params['adminphonenumber'];
    if (substr($localTelephone, 0, 1) == "0")
    {
        $localTelephone = substr($localTelephone, 1);
    }
    $admin->Telephone = $localTelephone;

    $billing = new Contact();
    $billing->City = $params["admincity"];
    $billing->CountryCode = $params['admincountry'];
    $billing->Email = $params['adminemail'];
    $billing->Name = $params['adminfirstname'] . ' ' . $params['adminlastname'];
    $billing->Street1 = $params['adminaddress1'];
    $billing->Street2 = $params['adminaddress2'];
    $billing->State = $params['adminstate'];
    $billing->Postcode = $params['adminpostcode'];
    $localTelephone = $params['adminphonenumber'];
    if (substr($localTelephone, 0, 1) == "0")
    {
        $localTelephone = substr($localTelephone, 1);
    }
    $billing->Telephone = $localTelephone;

    $tech = new Contact();
    $tech->City = $params["admincity"];
    $tech->CountryCode = $params['admincountry'];
    $tech->Email = $params['adminemail'];
    $tech->Name = $params['adminfirstname'] . ' ' . $params['adminlastname'];
    $tech->Street1 = $params['adminaddress1'];
    $tech->Street2 = $params['adminaddress2'];
    $tech->State = $params['adminstate'];
    $tech->Postcode = $params['adminpostcode'];
    $localTelephone = $params['adminphonenumber'];
    if (substr($localTelephone, 0, 1) == "0")
    {
        $localTelephone = substr($localTelephone, 1);
    }
    $tech->Telephone =  $localTelephone;

    $contacts = new Contacts();
    $contacts->Registrant = $registrant;
    $contacts->Admin = $admin;
    $contacts->Billing = $billing;
    $contacts->Tech = $tech;

    $transferDomainParameters->Nameservers = $nameServerParameters;
    $transferDomainParameters->Contacts = $contacts;

    $error = "";
    try
    {
        $parameters = array('AuthenticationParameters' => $authParameters, 'CommandParameters' => $transferDomainParameters);
        $client = new SoapClient($apiEndpoint, array('soap_version' => SOAP_1_2));
        $result = $client->RequestTransfer($parameters);

        $result = $result->RequestTransferResult;

        if ($result->ResultCode == 100)
        {
            // We need to store the domainID that comes back in the database.
            $table = "mod_domainbox";
            $values = array("whmcsDomainID"=> $params['domainid'], "domainboxDomainID"=> $result->DomainId);
            insert_query($table, $values);

        }
        else
        {
            $error = $result->ResultMsg;
        }
    }
    catch (Exception $e)
    {
        $error = "There was an error communicating with Domainbox";
    }

    # If error, return the error message in the value below
    $values["error"] = $error;
    return $values;

}

/**
 * Renews a domain for the specified registration period, the current expiry date must be obtained from the database
 * prior to attempting the renewal
 * @param $params
 * @return array
 */
function domainbox_RenewDomain($params)
{
    // Get the current expiry date from the database
    $table = "tbldomains";
    $fields = "id,expirydate";
    $where = array("id" => $params['domainid']);
    $result = select_query($table, $fields, $where);
    $data = mysql_fetch_array($result);
    $expiryDate = $data['expirydate'];

    $authParameters = getAuthParameters($params);
    $apiEndpoint = $params["TestMode"] ? "https://sandbox.domainbox.net/?WSDL" : "https://live.domainbox.net/?WSDL";

    $renewDomainParameters = new RenewDomainParameters();
    $renewDomainParameters->DomainName = $params["sld"] . '.' . $params["tld"];
    $renewDomainParameters->Period = $params['regperiod'];
    $renewDomainParameters->CurrentExpiry = $expiryDate;

    try
    {
        $parameters = array('AuthenticationParameters' => $authParameters, 'CommandParameters' => $renewDomainParameters);
        $client = new SoapClient($apiEndpoint, array('soap_version' => SOAP_1_2));
        $result = $client->RenewDomain($parameters);

        $result = $result->RenewDomainResult;

        if ($result->ResultCode <> 100)
        {
            $values["error"] = $result->ResultMsg;
        }

    }
    catch (Exception $e)
    {
        $values["error"] = "There was an error communicating with Domainbox";
    }

    return $values;

}

/**
 * Gets the contacts on the domain
 * @param $params
 * @return array
 */
function domainbox_GetContactDetails($params)
{
    $authParameters = getAuthParameters($params);
    $apiEndpoint = $params["TestMode"] ? "https://sandbox.domainbox.net/?WSDL" : "https://live.domainbox.net/?WSDL";

    $queryDomainContactsParameters = new QueryDomainContactsParameters();
    $queryDomainContactsParameters->DomainName = $params["sld"] . '.' . $params["tld"];

    try
    {
        $parameters = array('AuthenticationParameters' => $authParameters, 'CommandParameters' => $queryDomainContactsParameters);
        $client = new SoapClient($apiEndpoint, array('soap_version' => SOAP_1_2));
        $result = $client->QueryDomainContacts($parameters);

        $result = $result->QueryDomainContactsResult;

        if ($result->ResultCode == 100)
        {
            $registrant = $result->Contacts->Registrant;
            $admin = $result->Contacts->Admin;
            $tech = $result->Contacts->Tech;
            $billing = $result->Contacts->Billing;

            $values['Registrant']['Name'] = $registrant->Name;
            $values['Registrant']['Organisation'] = $registrant->Organisation;
            $values['Registrant']['Street 1'] = $registrant->Street1;
            $values['Registrant']['Street 2'] = $registrant->Street2;
            $values['Registrant']['Street 3'] = $registrant->Street3;
            $values['Registrant']['City'] = $registrant->City;
            $values['Registrant']['State'] = $registrant->State;
            $values['Registrant']['Postal Code'] = $registrant->Postcode;
            $values['Registrant']['Country Code'] = $registrant->CountryCode;
            $values['Registrant']['Telephone'] = $registrant->Telephone;
            $values['Registrant']['Fax'] = $registrant->Fax;
            $values['Registrant']['Email'] = $registrant->Email;

            if ($admin != null)
            {
                $values['Admin']['Name'] = $admin->Name;
                $values['Admin']['Organisation'] = $admin->Organisation;
                $values['Admin']['Street 1'] = $admin->Street1;
                $values['Admin']['Street 2'] = $admin->Street2;
                $values['Admin']['Street 3'] = $admin->Street3;
                $values['Admin']['City'] = $admin->City;
                $values['Admin']['State'] = $admin->State;
                $values['Admin']['Postal Code'] = $admin->Postcode;
                $values['Admin']['Country Code'] = $admin->CountryCode;
                $values['Admin']['Telephone'] = $admin->Telephone;
                $values['Admin']['Fax'] = $admin->Fax;
                $values['Admin']['Email'] = $admin->Email;
            }

            if ($billing != null)
            {
                $values['Billing']['Name'] = $billing->Name;
                $values['Billing']['Organisation'] = $billing->Organisation;
                $values['Billing']['Street 1'] = $billing->Street1;
                $values['Billing']['Street 2'] = $billing->Street2;
                $values['Billing']['Street 3'] = $billing->Street3;
                $values['Billing']['City'] = $billing->City;
                $values['Billing']['State'] = $billing->State;
                $values['Billing']['Postal Code'] = $billing->Postcode;
                $values['Billing']['Country Code'] = $billing->CountryCode;
                $values['Billing']['Telephone'] = $billing->Telephone;
                $values['Billing']['Fax'] = $billing->Fax;
                $values['Billing']['Email'] = $billing->Email;
            }

            if ($tech != null)
            {
                $values['Technical']['Name'] = $tech->Name;
                $values['Technical']['Organisation'] = $tech->Organisation;
                $values['Technical']['Street 1'] = $tech->Street1;
                $values['Technical']['Street 2'] = $tech->Street2;
                $values['Technical']['Street 3'] = $tech->Street3;
                $values['Technical']['City'] = $tech->City;
                $values['Technical']['State'] = $tech->State;
                $values['Technical']['Postal Code'] = $tech->Postcode;
                $values['Technical']['Country Code'] = $tech->CountryCode;
                $values['Technical']['Telephone'] = $tech->Telephone;
                $values['Technical']['Fax'] = $tech->Fax;
                $values['Technical']['Email'] = $tech->Email;
            }
        }
        else
        {
            $values["error"] = $result->ResultMsg;
        }

    }
    catch (Exception $e)
    {
        $values["error"] = "There was an error communicating with Domainbox";
    }

    return $values;
}

/**
 * Changes the contacts on the specified domain
 * @param $params
 * @return array
 */
function domainbox_SaveContactDetails($params)
{
    $authParameters = getAuthParameters($params);
    $apiEndpoint = $params["TestMode"] ? "https://sandbox.domainbox.net/?WSDL" : "https://live.domainbox.net/?WSDL";

    $modifyDomainContactsParameters = new ModifyDomainContactsParameters();
    $modifyDomainContactsParameters->DomainName = $params["sld"] . '.' . $params["tld"];
    $modifyDomainContactsParameters->Contacts = new Contacts();

    modifyDomainLock($params, false);

    $registrant = new Contact();
    $registrant->Name = $params["contactdetails"]["Registrant"]["Name"];
    $registrant->Organization = $params["contactdetails"]["Registrant"]["Organisation"];
    $registrant->Street1 = $params["contactdetails"]["Registrant"]["Street 1"];
    $registrant->Street2 = $params["contactdetails"]["Registrant"]["Street 2"];
    $registrant->Street3 = $params["contactdetails"]["Registrant"]["Street 3"];
    $registrant->City = $params["contactdetails"]["Registrant"]["City"];
    $registrant->State = $params["contactdetails"]["Registrant"]["State"];
    $registrant->Postcode = $params["contactdetails"]["Registrant"]["Postal Code"];
    $registrant->CountryCode = $params["contactdetails"]["Registrant"]["Country Code"];
    $registrant->Telephone = $params["contactdetails"]["Registrant"]["Telephone"];
    $registrant->Fax = $params["contactdetails"]["Registrant"]["Fax"];
    $registrant->Email = $params["contactdetails"]["Registrant"]["Email"];

    $admin = new Contact();
    $admin->Name = $params["contactdetails"]["Admin"]["Name"];
    $admin->Organization = $params["contactdetails"]["Admin"]["Organisation"];
    $admin->Street1 = $params["contactdetails"]["Admin"]["Street 1"];
    $admin->Street2 = $params["contactdetails"]["Admin"]["Street 2"];
    $admin->Street3 = $params["contactdetails"]["Admin"]["Street 3"];
    $admin->City = $params["contactdetails"]["Admin"]["City"];
    $admin->State = $params["contactdetails"]["Admin"]["State"];
    $admin->Postcode = $params["contactdetails"]["Admin"]["Postal Code"];
    $admin->CountryCode = $params["contactdetails"]["Admin"]["Country Code"];
    $admin->Telephone = $params["contactdetails"]["Admin"]["Telephone"];
    $admin->Fax = $params["contactdetails"]["Admin"]["Fax"];
    $admin->Email = $params["contactdetails"]["Admin"]["Email"];

    $tech = new Contact();
    $tech->Name = $params["contactdetails"]["Technical"]["Name"];
    $tech->Organization = $params["contactdetails"]["Technical"]["Organisation"];
    $tech->Street1 = $params["contactdetails"]["Technical"]["Street 1"];
    $tech->Street2 = $params["contactdetails"]["Technical"]["Street 2"];
    $tech->Street3 = $params["contactdetails"]["Technical"]["Street 3"];
    $tech->City = $params["contactdetails"]["Technical"]["City"];
    $tech->State = $params["contactdetails"]["Technical"]["State"];
    $tech->Postcode = $params["contactdetails"]["Technical"]["Postal Code"];
    $tech->CountryCode = $params["contactdetails"]["Technical"]["Country Code"];
    $tech->Telephone = $params["contactdetails"]["Technical"]["Telephone"];
    $tech->Fax = $params["contactdetails"]["Technical"]["Fax"];
    $tech->Email = $params["contactdetails"]["Technical"]["Email"];

    $billing = new Contact();
    $billing->Name = $params["contactdetails"]["Billing"]["Name"];
    $billing->Organization = $params["contactdetails"]["Billing"]["Organisation"];
    $billing->Street1 = $params["contactdetails"]["Billing"]["Street 1"];
    $billing->Street2 = $params["contactdetails"]["Billing"]["Street 2"];
    $billing->Street3 = $params["contactdetails"]["Billing"]["Street 3"];
    $billing->City = $params["contactdetails"]["Billing"]["City"];
    $billing->State = $params["contactdetails"]["Billing"]["State"];
    $billing->Postcode = $params["contactdetails"]["Billing"]["Postal Code"];
    $billing->CountryCode = $params["contactdetails"]["Billing"]["Country Code"];
    $billing->Telephone = $params["contactdetails"]["Billing"]["Telephone"];
    $billing->Fax = $params["contactdetails"]["Billing"]["Fax"];
    $billing->Email = $params["contactdetails"]["Billing"]["Email"];

    $contacts = new Contacts();
    $contacts->Admin = $admin;
    $contacts->Billing = $billing;
    $contacts->Registrant = $registrant;
    $contacts->Tech = $tech;

    $modifyDomainContactsParameters->Contacts = $contacts;

    $error = "";
    try
    {
        $parameters = array('AuthenticationParameters' => $authParameters, 'CommandParameters' => $modifyDomainContactsParameters);
        $client = new SoapClient($apiEndpoint, array('soap_version' => SOAP_1_2));
        $result = $client->ModifyDomainContacts($parameters);

        $result = $result->ModifyDomainContactsResult;

        if ($result->ResultCode <> 100)
        {
            $error = $result->ResultMsg;
        }

    }
    catch (Exception $e)
    {
        $error = $e->getMessage();
    }

    $values["error"] = $error;

    modifyDomainLock($params, true);

    return $values;
}

/**
 * Gets the EPP auth code for the domain
 * @param $params
 * @return array
 */
function domainbox_GetEPPCode($params)
{
    $authParameters = getAuthParameters($params);
    $apiEndpoint = $params["TestMode"] ? "https://sandbox.domainbox.net/?WSDL" : "https://live.domainbox.net/?WSDL";

    $queryAuthCodeParameters = new QueryDomainAuthCodeParameters();
    $queryAuthCodeParameters->DomainName = $params["sld"] . '.' . $params["tld"];

    try
    {
        $parameters = array('AuthenticationParameters' => $authParameters, 'CommandParameters' => $queryAuthCodeParameters);
        $client = new SoapClient($apiEndpoint, array('soap_version' => SOAP_1_2));
        $result = $client->QueryDomainAuthcode($parameters);

        $result = $result->QueryDomainAuthcodeResult;

        if ($result->ResultCode == 100)
        {
            $values["eppcode"] = $result->AuthCode;
        }
        else
        {
            $values["error"] = $result->ResultMsg;
        }

    }
    catch (Exception $e)
    {
        $values["error"] = "There was an error communicating with Domainbox";
    }

    return $values;
}

/**
 * Creates a glue record for the specified domain
 * @param $params
 * @return array
 */
function domainbox_RegisterNameserver($params)
{
    // API Configuration
    $authParameters = getAuthParameters($params);
    $apiEndpoint = $params["TestMode"] ? "https://sandbox.domainbox.net/?WSDL" : "https://live.domainbox.net/?WSDL";

    // Command parameters
    $createNameserversParameters = new CreateNameserverParameters();
    $createNameserversParameters->DomainName = $params["sld"] . '.' . $params["tld"];
    $createNameserversParameters->HostName = $params['nameserver'];

    // IP Address to use for this nameserver
    $createNameserversParameters->IPAddresses = new IPAddressesParameters();
    $createNameserversParameters->IPAddresses->IPv4Addresses = new IPv4AddressesParameter();
    $createNameserversParameters->IPAddresses->IPv4Addresses->string[] = $params['ipaddress'];

    $values['error'] = '';
    try
    {
        $parameters = array('AuthenticationParameters' => $authParameters, 'CommandParameters' => $createNameserversParameters);
        $client = new SoapClient($apiEndpoint, array('soap_version' => SOAP_1_2));
        $result = $client->CreateNameserver($parameters);

        $result = $result->CreateNameserverResult;

        if ($result->ResultCode <> 100)
        {
            $values["error"] = "ERROR";
        }

    }
    catch (Exception $e)
    {
        $values["error"] = "There was an error communicating with Domainbox";
    }

    return $values;
}

/**
 * Modifies the IP address assigned to a nameserver
 * @param $params
 * @return array
 */
function domainbox_ModifyNameserver($params)
{
    // API Configurations
    $authParameters = getAuthParameters($params);
    $apiEndpoint = $params["TestMode"] ? "https://sandbox.domainbox.net/?WSDL" : "https://live.domainbox.net/?WSDL";

    // Command parameters
    $modifyNameserverParameters = new ModifyNameserverParameters();
    $modifyNameserverParameters->DomainName = $params["sld"] . '.' . $params["tld"];
    $modifyNameserverParameters->HostName = $params['nameserver'];

    // IP Address to remove
    $modifyNameserverParameters->RemoveIPAddresses = new IPAddressesParameters();
    $modifyNameserverParameters->RemoveIPAddresses->IPv4Addresses = new IPv4AddressesParameter();
    $modifyNameserverParameters->RemoveIPAddresses->IPv4Addresses->string[] = $params["currentipaddress"];

    // IP Address to add
    $modifyNameserverParameters->AddIPAddresses = new IPAddressesParameters();
    $modifyNameserverParameters->AddIPAddresses->IPv4Addresses = new IPv4AddressesParameter();
    $modifyNameserverParameters->AddIPAddresses->IPv4Addresses->string[] = $params["newipaddress"];

    $values['error'] = "";

    try
    {
        $parameters = array('AuthenticationParameters' => $authParameters, 'CommandParameters' => $modifyNameserverParameters);
        $client = new SoapClient($apiEndpoint, array('soap_version' => SOAP_1_2));
        $result = $client->ModifyNameserver($parameters);

        $result = $result->ModifyNameserverResult;

        if ($result->ResultCode <> 100)
        {
            $values["error"] = $result->ResultMsg;
        }
    }
    catch (Exception $e)
    {
        $values["error"] = "There was an error communicating with Domainbox";
    }

    return $values;
}

/**
 * Deletes the specified nameserver
 * @param $params
 * @return array
 */
function domainbox_DeleteNameserver($params)
{
    // API Configurations
    $authParameters = getAuthParameters($params);
    $apiEndpoint = $params["TestMode"] ? "https://sandbox.domainbox.net/?WSDL" : "https://live.domainbox.net/?WSDL";

    // Command parameters
    $deleteNameserverParameters = new DeleteNameserverParameters();
    $deleteNameserverParameters->DomainName = $params["sld"] . '.' . $params["tld"];
    $deleteNameserverParameters->HostName = $params['nameserver'];

    $values['error'] = "";

    try
    {
        $parameters = array('AuthenticationParameters' => $authParameters, 'CommandParameters' => $deleteNameserverParameters);
        $client = new SoapClient($apiEndpoint, array('soap_version' => SOAP_1_2));
        $result = $client->DeleteNameserver($parameters);

        $result = $result->DeleteNameserverResult;

        if ($result->ResultCode <> 100)
        {
            $values["error"] = $result->ResultMsg;
        }

    }
    catch (Exception $e)
    {
        $values["error"] = "There was an error communicating with Domainbox";
    }

    return $values;
}

/**
 * Returns the domainbox auth parameters required to connect to the IP
 * @param $params
 * @return AuthenticationParameters
 */
function getAuthParameters($params)
{
    $authParameters = new AuthenticationParameters();
    $authParameters->Reseller = $params['Reseller'];
    $authParameters->Username = $params['Username'];
    $authParameters->Password = $params['Password'];

    return $authParameters;
}

/**
 * Locks/Unlocks the specified domain
 * @param $params
 * @param $applyLock
 * @internal param $domainName
 * @return bool
 */
function modifyDomainLock($params, $applyLock)
{
    // API Configurations
    $authParameters = getAuthParameters($params);
    $apiEndpoint = $params["TestMode"] ? "https://sandbox.domainbox.net/?WSDL" : "https://live.domainbox.net/?WSDL";

    // Command parameters
    $modifyDomainLockParameters = new ModifyDomainLockParameters();
    $modifyDomainLockParameters->DomainName = $params["sld"] . '.' . $params["tld"];
    $modifyDomainLockParameters->ApplyLock = $applyLock;

    try
    {
        $parameters = array('AuthenticationParameters' => $authParameters, 'CommandParameters' => $modifyDomainLockParameters);
        $client = new SoapClient($apiEndpoint, array('soap_version' => SOAP_1_2));
        $result = $client->ModifyDomainLock($parameters);
        $result = $result->ModifyDomainLockResult;
        return $result->ResultCode == 100;
    }
    catch (Exception $e)
    {
        return false;
    }
}

/**
 * Checks if the specified domain is locked at the registry
 * @param $params
 * @return bool True if the domain is locked, false if not
 */
function queryDomainLock($params)
{
    // API Configurations
    $authParameters = getAuthParameters($params);
    $apiEndpoint = $params["TestMode"] ? "https://sandbox.domainbox.net/?WSDL" : "https://live.domainbox.net/?WSDL";

    // Command parameters
    $queryDomainLockParameters = new QueryDomainLockParameters();
    $queryDomainLockParameters->DomainName = $params["sld"] . '.' . $params["tld"];

    try
    {
        $parameters = array('AuthenticationParameters' => $authParameters, 'CommandParameters' => $queryDomainLockParameters);
        $client = new SoapClient($apiEndpoint, array('soap_version' => SOAP_1_2));
        $result = $client->QueryDomainLock($parameters);
        $result = $result->QueryDomainLockResult;

        return $result->ApplyLock;
    }
    catch (Exception $e)
    {
        return false;
    }
}

/**
 * Checks if the specified expiry string is in the past
 * @param $expiryString string The EPP expiry date in the format YYYY-MM-DD
 * @return bool True if the domain has expired, False if not
 */
function hasDomainExpired($expiryString)
{
    $expiry = DateTime::createFromFormat("Y-m-d", $expiryString);
    $today = new DateTime();
    $difference = $today->diff($expiry);
    return $difference->invert == 1;
}

?>
