<?php

namespace App\Enums;

/**
 * État d'un compte iCloud CalDAV.
 *
 * invalid_credentials est un état TERMINAL : un 401 iCloud signifie que le
 * mot de passe d'application a été révoqué (ou que le mot de passe principal
 * Apple a changé, ce qui révoque tous les mots de passe d'application).
 * On notifie l'utilisateur, on ne boucle jamais en retry.
 */
enum CalendarAccountStatus: string
{
    case Pending = 'pending';
    case Connected = 'connected';
    case InvalidCredentials = 'invalid_credentials';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Connexion en cours',
            self::Connected => 'Connecté',
            self::InvalidCredentials => 'Identifiants refusés',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::InvalidCredentials;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
