<?php
declare(strict_types=1);

namespace PenPay\Domain\User\Factory;

use PenPay\Domain\Shared\Kernel\UserId;
use PenPay\Domain\User\Aggregate\User;
use PenPay\Domain\User\ValueObject\{
    Email,
    PhoneNumber,
    DerivLoginId,
    KycSnapshot,
    PasswordHash
};

final class UserFactory
{
    public static function reconstitute(array $data): User
    {
        // Handle both flat and nested structures
        $u = $data['users'] ?? $data;

        // Required fields from users table
        $uuid = $u['uuid'] ?? throw new \InvalidArgumentException('Missing uuid');
        $email = $u['email'] ?? throw new \InvalidArgumentException('Missing email');
        $phone = $u['phone_e164'] ?? throw new \InvalidArgumentException('Missing phone_e164');
        
        // Get loginid - check multiple possible locations
        $loginId = $u['deriv_login_id'] 
            ?? $data['deriv_login_id'] 
            ?? $data['deriv_accounts']['loginid'] 
            ?? null;
        
        if (!$loginId) {
            throw new \InvalidArgumentException('Missing deriv_login_id');
        }
        
        // Build value objects
        $userId = UserId::fromString($uuid);
        $emailVo = Email::fromString($email);
        $phoneVo = PhoneNumber::fromE164($phone);
        $loginIdVo = DerivLoginId::fromString($loginId);

        // Password hash
        $passwordHash = $u['password_hash'] ?? $data['password_hash'] ?? null;
        
        if (!$passwordHash) {
            throw new \InvalidArgumentException('Missing password_hash');
        }

        // KycSnapshot: prefer dedicated kyc/profile data if available
        $kycVo = self::buildKycSnapshot($data, $email, $phone, $u);

        $pwdVo = PasswordHash::fromHash($passwordHash);

        // Devices (if provided)
        $devices = $data['devices'] ?? [];

        return User::reconstitute(
            id: $userId,
            email: $emailVo,
            phone: $phoneVo,
            derivLoginId: $loginIdVo,
            kyc: $kycVo,
            passwordHash: $pwdVo,
            devices: $devices
        );
    }

    private static function buildKycSnapshot(array $data, string $email, string $phone, array $u): KycSnapshot
    {
        // If we have dedicated KYC data, use it
        if (isset($data['kyc']) && is_array($data['kyc']) && !empty($data['kyc'])) {
            return KycSnapshot::fromArray($data['kyc']);
        }
        
        // If we have profile data with required fields, use it
        if (isset($data['profile']) && is_array($data['profile']) && !empty($data['profile'])) {
            $profile = $data['profile'];
            
            // Check if profile has minimum required fields
            $hasRequiredFields = !empty($profile['first_name']) 
                && !empty($profile['last_name'])
                && !empty($profile['date_of_birth']);
            
            if ($hasRequiredFields) {
                return KycSnapshot::fromArray([
                    'first_name' => $profile['first_name'],
                    'last_name' => $profile['last_name'],
                    'email' => $profile['email'] ?? $email,
                    'phone' => $profile['phone'] ?? $phone,
                    'country' => $profile['country'] ?? ($u['country'] ?? 'KE'),
                    'date_of_birth' => $profile['date_of_birth'],
                    'place_of_birth' => $profile['place_of_birth'] ?? 'KE',
                    'residence' => $profile['residence'] ?? 'KE',
                ]);
            }
        }
        
        // No profile/kyc data available - return empty snapshot
        return KycSnapshot::empty();
    }
}