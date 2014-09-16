<?php
class AuthenticationParameters
{
    public $Reseller;
    public $Username;
    public $Password;
}

class QueryDomainAuthCodeParameters
{
    public $DomainName;
}

class QueryDomainDatesParameters
{
    public $DomainName;
}

class QueryDomainLockParameters
{
    public $DomainName;
}

class QueryDomainContactsParameters
{
    public $DomainName;
}

class QueryDomainNameserversParameters
{
    public $DomainName;
}

class QueryDnsRecordsParameters
{
    public $Zone;
    public $PageNumber = 1;
}

class QueryTransferParameters
{
    public $DomainName;
    public $DomainId = 0;
}

class ModifyDomainLockParameters
{
    public $DomainName;
    public $ApplyLock;
}

class ModifyDomainNameserversParameters
{
    public $DomainName;
    public $Nameservers;
}

class ModifyDnsRecordsParameters
{
    public $Zone;
    public $Records;
}

class ModifyNameserverParameters
{
    public $DomainName;
    public $Username;
    public $AddIPAddresses;
    public $RemoveIPAddresses;
}

class ModifyDomainContactsParameters
{
    public $DomainName;
    public $Contacts;
    public $AcceptTerms = true;
}

class CreateDnsZoneParameters
{
    public $Zone;
}

class CreateDnsRecordsParameters
{
    public $Zone;
    public $Records;
}

class CreateNameserverParameters
{
    public $DomainName;
    public $HostName;
    public $IPAddresses;
}

class DeleteNameserverParameters
{
    public $DomainName;
    public $HostName;
}

class DeleteDnsRecordsParameters
{
    public $Zone;
    public $Records;
}

class RenewDomainParameters
{
    public $DomainName;
    public $Period;
    public $CurrentExpiry;
}

class RequestTransferParameters
{
    public $DomainName;
    public $AutoRenew = false;
    public $AutoRenewDays = 7;
    public $AcceptTerms = true;
    public $KeepExistingNameservers = false;
    public $Nameservers;
    public $Contacts;
}

class IPAddressesParameters
{
    public $IPv4Addresses;
    public $IPv6Addresses;
}

class IPv4AddressesParameter
{
    public $String = array();
}

class IPv6AddressParameter
{
    public $String = array();
}

class DnsRecordParameter
{
    public $RecordType;
    public $HostName;
    public $Content;
    public $Priority;
    public $Weight = 0;
    public $Port = 0;
    public $OldContent;
    public $OldWeight = 0;
    public $OldPort = 0;
    public $OldPriority = 0;
}

class RegisterDomainParameters
{
    public $DomainName;
    public $LaunchPhase;
    public $Period;
    public $ApplyLock = true;
    public $AutoRenew = false;
    public $AutoRenewDays = 7;
    public $ApplyPrivacy = false;
    public $AcceptTerms = true;
    public $Nameservers = array();
    public $Contacts = array();
    public $Extension;
}

class Nameservers
{
    public $NS1;
    public $NS2;
    public $NS3;
    public $NS4;
    public $NS5;
}

class Contacts
{
    public $Registrant;
    public $Admin;
    public $Billing;
    public $Tech;
}

class Contact
{
    public $Name;
    public $Organization;
    public $Street1;
    public $Street2;
    public $Street3;
    public $City;
    public $State;
    public $Postcode;
    public $CountryCode;
    public $Telephone;
    public $Fax;
    public $Email;
    public $AdditionalData;
}

class AdditionalDataParameter
{
    public $UKAdditionalData;
    public $USAdditionalData;
    public $EUBEAdditionalData;
    public $ESAdditionalData;
    public $NLAdditionalData;
    public $AfnicAdditionalData;
    public $ITAdditionalData;
}

class UKAdditionalDataParameter
{
    public $RegistrantType;
    public $CompanyNumber;
    public $TradingName;

    public function mapTypeToDomainbox($registrantType, $countryCode)
    {
        switch ($registrantType)
        {
            case "Individual":
                switch ($countryCode)
                {
                    case "GB":
                        $this->RegistrantType = "IND";
                        break;
                    default:
                        $this->RegistrantType = "FIND";
                }
                break;
            case "UK Limited Company":
                $this->RegistrantType = "LTD";
                break;
            case "UK Public Limited Company":
                $this->RegistrantType = "PLC";
                break;
            case "UK Partnership":
                $this->RegistrantType = "PTNR";
                break;
            case "UK Limited Liability Partnership":
                $this->RegistrantType = "LLP";
                break;
            case "Sole Trader":
                $this->RegistrantType = "STRA";
                break;
            case "UK Registered Chairty":
                $this->RegistrantType = "RCHAR";
                break;
            case "UK Entity (other)":
                $this->RegistrantType = "OTHER";
                break;
            case "Foreign Organization":
            case "Other foreign organizations":
                $this->RegistrantType = "FCORP";
                break;
        }
    }
}

class USAdditionalDataParameter
{
    public $AppPurpose;
    public $Category;

    public function mapTypeToDomainbox($type)
    {
        switch ($type)
        {
            case 'Business use for profit':
                $this->AppPurpose = 'P1';
                break;
            case 'Personal Use':
                $this->AppPurpose = 'P3';
                break;
            case 'Education purposes';
                $this->AppPurpose = 'P4';
                break;
            case 'Government purposes':
                $this->AppPurpose = 'P5';
                break;
            default:
                $this->AppPurpose = 'P2';
        }
    }
}

class EUBEAdditionalDataParameter
{
    public $Language;
    public $VATNumber;
    public $ContactType;
}

class ESAdditionalDataParameter
{
    public $IdentificationType;
    public $IdentificationNumber;
    public $ContactType;
}

class NLAdditionalDataParameter
{
    public $LegalType;
    public $LegalTypeRegistrationNumber;
}

class AfnicAdditionalDataParameter
{
    public $BirthDate;
    public $BirthCc;
    public $BirthCity;
    public $BirthPc;
}

class ITAdditionalDataParameter
{
    public $Nationality;
    public $EntityType;
    public $RegCode;
}

class ExtensionParameter
{
    public $ProProfessionData;
}

class ProProfessionDataParameter
{
    public $Profession;
    public $Authority;
    public $AuthorityWebsite;
    public $LicenceNumber;
}


?>