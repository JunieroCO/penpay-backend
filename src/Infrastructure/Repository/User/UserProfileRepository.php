<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Repository\User;

use PenPay\Domain\Shared\Kernel\UserId;
use PenPay\Domain\User\ValueObject\KycSnapshot;
use PDO;

final class UserProfileRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Domain-aware writer: saveProfile(UserId, KycSnapshot)
     */
    public function saveProfile(string $uuid, KycSnapshot $kyc): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO user_profile (user_id, first_name, last_name, date_of_birth, country, country_code, residence, place_of_birth, calling_country_code, phone_country_code, email_consent, feature_flag_wallet, has_secret_answer, immutable_fields, updated_at)
VALUES (:user_id, :first_name, :last_name, :dob, :country, :country_code, :residence, :place_of_birth, :calling_country_code, :phone_country_code, :email_consent, :feature_flag_wallet, :has_secret_answer, :immutable_fields, :now)
ON DUPLICATE KEY UPDATE
  first_name = VALUES(first_name),
  last_name = VALUES(last_name),
  date_of_birth = VALUES(date_of_birth),
  country = VALUES(country),
  country_code = VALUES(country_code),
  residence = VALUES(residence),
  place_of_birth = VALUES(place_of_birth),
  calling_country_code = VALUES(calling_country_code),
  phone_country_code = VALUES(phone_country_code),
  email_consent = VALUES(email_consent),
  feature_flag_wallet = VALUES(feature_flag_wallet),
  has_secret_answer = VALUES(has_secret_answer),
  immutable_fields = VALUES(immutable_fields),
  updated_at = VALUES(updated_at)
SQL
        );

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt->execute([
            ':user_id' => $uuid,
            ':first_name' => $kyc->firstName,
            ':last_name' => $kyc->lastName,
            ':dob' => (new \DateTimeImmutable("@{$kyc->dateOfBirthTimestamp}"))->format('Y-m-d'),
            ':country' => $kyc->countryCode,
            ':country_code' => $kyc->countryCode,
            ':residence' => $kyc->residence,
            ':place_of_birth' => $kyc->placeOfBirth,
            ':calling_country_code' => $kyc->phoneNumber ? substr($kyc->phoneNumber, 1, 3) : null,
            ':phone_country_code' => $kyc->phoneNumber ? substr($kyc->phoneNumber, 1, 3) : null,
            ':email_consent' => $kyc->emailConsent ? 1 : 0,
            ':feature_flag_wallet' => 0,
            ':has_secret_answer' => $kyc->phoneVerified ? 1 : 0,
            ':immutable_fields' => json_encode([]),
            ':now' => $now,
        ]);
    }

    public function findByUserId(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_profile WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}