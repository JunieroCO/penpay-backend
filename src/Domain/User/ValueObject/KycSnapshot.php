<?php
declare(strict_types=1);

namespace PenPay\Domain\User\ValueObject;

use InvalidArgumentException;

final readonly class KycSnapshot
{
    private function __construct(
        public string $firstName,
        public string $lastName,
        public string $email,
        public string $phoneNumber,          // E.164 +254...
        public string $countryCode,          // ke → KE
        public int $dateOfBirthTimestamp,    // Unix timestamp from Deriv
        public string $accountOpeningReason,
        public string $addressCity,
        public string $addressLine1,
        public ?string $addressLine2 = null,
        public ?string $addressPostcode = null,
        public ?string $addressState = null,
        public string $employmentStatus,
        public ?string $citizen = null,
        public string $placeOfBirth,         // ke
        public string $residence,            // ke
        public ?string $taxIdentificationNumber = null,
        public bool $emailConsent = false,
        public bool $nonPepDeclaration = false,
        public bool $phoneVerified = false,
    ) {}

    public static function fromDerivResponse(array $response): self
    {
        $get = $response['get_settings'] ?? throw new InvalidArgumentException('Invalid Deriv response');

        $required = ['first_name', 'last_name', 'email', 'phone', 'country', 'date_of_birth', 'place_of_birth', 'residence'];
        foreach ($required as $key) {
            if (empty($get[$key])) {
                throw new InvalidArgumentException("KYC missing required field: {$key}");
            }
        }

        return new self(
            firstName: trim($get['first_name']),
            lastName: trim($get['last_name']),
            email: strtolower(trim($get['email'])),
            phoneNumber: '+' . ltrim($get['phone'], '+0'),
            countryCode: strtoupper($get['country_code'] ?? $get['country']),
            dateOfBirthTimestamp: (int) $get['date_of_birth'],
            accountOpeningReason: $get['account_opening_reason'] ?? '',
            addressCity: $get['address_city'] ?? '',
            addressLine1: $get['address_line_1'] ?? '',
            addressLine2: $get['address_line_2'] ?? null,
            addressPostcode: $get['address_postcode'] ?? null,
            addressState: $get['address_state'] ?? null,
            employmentStatus: $get['employment_status'] ?? 'Unknown',
            citizen: $get['citizen'] ?? null,
            placeOfBirth: strtoupper($get['place_of_birth']),
            residence: strtoupper($get['residence']),
            taxIdentificationNumber: $get['tax_identification_number'] ?? null,
            emailConsent: !empty($get['email_consent']),
            nonPepDeclaration: !empty($get['non_pep_declaration']),
            phoneVerified: ($get['phone_number_verification']['verified'] ?? false) === 1,
        );
    }

    public static function fromArray(array $data): self
    {
        // required minimum fields
        $first = $data['first_name'] ?? $data['firstName'] ?? $data['first'] ?? null;
        $last  = $data['last_name'] ?? $data['lastName'] ?? $data['last'] ?? null;
        $email = $data['email'] ?? null;
        $phone = $data['phone_number'] ?? $data['phone'] ?? null;
        $country = $data['country_code'] ?? $data['country'] ?? null;
        $dob = $data['date_of_birth'] ?? $data['date_of_birth_timestamp'] ?? $data['dateOfBirth'] ?? null;

        if (empty($first) || empty($last) || empty($email) || empty($phone) || empty($country) || empty($dob)) {
            throw new InvalidArgumentException('Invalid KycSnapshot array - missing required fields');
        }

        // Normalize DOB: accept timestamp or Y-m-d string
        if (is_numeric($dob)) {
            $dobTs = (int) $dob;
        } else {
            $dt = new \DateTimeImmutable((string) $dob);
            $dobTs = (int) $dt->getTimestamp();
        }

        return new self(
            firstName: trim((string) $first),
            lastName: trim((string) $last),
            email: strtolower(trim((string) $email)),
            phoneNumber: (string) $phone,
            countryCode: strtoupper((string) $country),
            dateOfBirthTimestamp: $dobTs,
            accountOpeningReason: $data['account_opening_reason'] ?? $data['accountOpeningReason'] ?? '',
            addressCity: $data['city'] ?? $data['address_city'] ?? '',
            addressLine1: $data['address_line_1'] ?? $data['addressLine1'] ?? $data['address'] ?? '',
            addressLine2: $data['address_line_2'] ?? $data['addressLine2'] ?? null,
            addressPostcode: $data['address_postcode'] ?? $data['postcode'] ?? null,
            addressState: $data['address_state'] ?? $data['state'] ?? null,
            employmentStatus: $data['employment_status'] ?? $data['employmentStatus'] ?? 'Unknown',
            citizen: $data['citizen'] ?? null,
            placeOfBirth: strtoupper((string) ($data['place_of_birth'] ?? $data['placeOfBirth'] ?? '')),
            residence: strtoupper((string) ($data['residence'] ?? '')),
            taxIdentificationNumber: $data['tax_id'] ?? $data['taxIdentificationNumber'] ?? null,
            emailConsent: !empty($data['email_consent'] ?? $data['emailConsent'] ?? false),
            nonPepDeclaration: !empty($data['non_pep'] ?? $data['nonPep'] ?? false),
            phoneVerified: ($data['phone_verified'] ?? $data['phoneVerified'] ?? false) === true || ($data['phone_verified'] ?? $data['phoneVerified'] ?? false) === 1,
        );
    }

    public function fullName(): string
    {
        return trim("{$this->firstName} {$this->lastName}");
    }

    public function dateOfBirth(): string
    {
        return (new \DateTimeImmutable("@{$this->dateOfBirthTimestamp}"))->format('Y-m-d');
    }

    public function isKenyan(): bool
    {
        return $this->countryCode === 'KE';
    }

    public function isPhoneVerified(): bool
    {
        return $this->phoneVerified;
    }

    public function toArray(): array
    {
        return [
            'first_name'               => $this->firstName,
            'last_name'                => $this->lastName,
            'full_name'                => $this->fullName(),
            'email'                    => $this->email,
            'phone_number'             => $this->phoneNumber,
            'country_code'             => $this->countryCode,
            'date_of_birth'            => $this->dateOfBirth(),
            'account_opening_reason'       => $this->accountOpeningReason,
            'address'                  => trim("{$this->addressLine1} {$this->addressLine2}"),
            'city'                     => $this->addressCity,
            'postcode'                 => $this->addressPostcode,
            'state'                    => $this->addressState,
            'employment_status'        => $this->employmentStatus,
            'citizen'                  => $this->citizen,
            'place_of_birth'           => $this->placeOfBirth,
            'residence'                => $this->residence,
            'tax_id'                   => $this->taxIdentificationNumber,
            'email_consent'            => $this->emailConsent,
            'non_pep'                  => $this->nonPepDeclaration,
            'phone_verified'           => $this->phoneVerified,
        ];
    }
}