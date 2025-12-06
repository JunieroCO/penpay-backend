<?php
declare(strict_types=1);

namespace PenPay\Application\User\EventHandler;

use PenPay\Domain\User\Event\UserRegistered;
use PenPay\Domain\User\Repository\UserRepositoryInterface;
use PenPay\Infrastructure\Repository\User\{
    UserProfileRepository,
    UserAddressRepository,
    UserComplianceRepository,
    UserPhoneVerificationRepository
};

final class UserRegisteredHandler
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly UserProfileRepository $profileRepository,
        private readonly UserAddressRepository $addressRepository,
        private readonly UserComplianceRepository $complianceRepository,
        private readonly UserPhoneVerificationRepository $phoneVerificationRepository,
    ) {}

    public function handle(UserRegistered $event): void
    {
        // Fetch the full user aggregate to get KYC data
        $user = $this->userRepository->getById($event->userId);
        $kyc = $user->kyc();

        // Get internal user ID from database
        $userId = $this->getInternalUserId((string)$event->userId);

        if (!$userId) {
            throw new \RuntimeException("User not found: {$event->userId}");
        }

        // Save profile data
        if ($kyc->firstName || $kyc->lastName) {
            $this->saveProfile($userId, $kyc);
        }

        // Save address data
        if ($kyc->addressCity || $kyc->addressLine1) {
            $this->saveAddress($userId, $kyc);
        }

        // Save compliance data
        if ($kyc->employmentStatus || $kyc->accountOpeningReason) {
            $this->saveCompliance($userId, $kyc);
        }

        // Save phone verification
        if ($kyc->phoneVerified) {
            $this->savePhoneVerification($userId, $kyc);
        }
    }

    private function getInternalUserId(string $uuid): ?int
    {
        // This should be injected via a repository method, but for now:
        // You'll need access to PDO or add a method to UserRepository
        return null; // TODO: Implement this
    }

    private function saveProfile(int $userId, $kyc): void
    {
        $this->profileRepository->saveProfile((string)$userId, $kyc);
    }

    private function saveAddress(int $userId, $kyc): void
    {
        $address = [
            'address_line_1' => $kyc->addressLine1,
            'address_line_2' => $kyc->addressLine2,
            'city' => $kyc->addressCity,
            'state' => $kyc->addressState,
            'postcode' => $kyc->addressPostcode,
        ];
        
        $this->addressRepository->saveAddress((string)$userId, $address);
    }

    private function saveCompliance(int $userId, $kyc): void
    {
        $compliance = [
            'employment_status' => $kyc->employmentStatus,
            'account_opening_reason' => $kyc->accountOpeningReason,
            'fatca_declaration' => 0,
            'non_pep_declaration' => $kyc->nonPepDeclaration ? 1 : 0,
            'tin_number' => $kyc->taxIdentificationNumber,
            'tax_residence' => $kyc->residence,
            'tnc_status' => [],
        ];
        
        $this->complianceRepository->saveCompliance((string)$userId, $compliance);
    }

    private function savePhoneVerification(int $userId, $kyc): void
    {
        $payload = [
            'phone' => $kyc->phoneNumber,
            'verified' => $kyc->phoneVerified,
            'last_verified_at' => $kyc->phoneVerified ? (new \DateTimeImmutable())->format('Y-m-d H:i:s') : null,
        ];
        
        $this->phoneVerificationRepository->savePhoneVerification((string)$userId, $payload);
    }
}