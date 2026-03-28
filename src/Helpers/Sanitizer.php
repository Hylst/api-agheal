<?php
// src/Helpers/Sanitizer.php
// Centralise les fonctions de nettoyage / validation des données en entrée.
namespace App\Helpers;

use DateTime;

class Sanitizer
{
    /**
     * Nettoie un champ de texte libre (noms, descriptions, commentaires...).
     * Supprime les balises HTML, échappe les entités.
     */
    public static function text(?string $value, int $maxLength = 500): string
    {
        if ($value === null) return '';
        $value = strip_tags($value);
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return mb_substr(trim($value), 0, $maxLength);
    }

    /**
     * Valide et retourne un email assaini, ou null si invalide.
     */
    public static function email(?string $value): ?string
    {
        if ($value === null) return null;
        $sanitized = filter_var(trim($value), FILTER_SANITIZE_EMAIL);
        return filter_var($sanitized, FILTER_VALIDATE_EMAIL) ? $sanitized : null;
    }

    /**
     * Valide une date au format YYYY-MM-DD.
     */
    public static function date(?string $value): ?string
    {
        if (empty($value)) return null;
        $d = DateTime::createFromFormat('Y-m-d', $value);
        return ($d && $d->format('Y-m-d') === $value) ? $value : null;
    }

    /**
     * Valide une heure au format HH:MM ou HH:MM:SS.
     */
    public static function time(?string $value): ?string
    {
        if (empty($value)) return null;
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
            return $value;
        }
        return null;
    }

    /**
     * Valide qu'une valeur est dans une liste autorisée (enum).
     */
    public static function enum(?string $value, array $allowed): ?string
    {
        return in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * Valide et cast un entier dans une plage optionnelle.
     */
    public static function integer($value, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false) return null;
        return ($int >= $min && $int <= $max) ? $int : null;
    }

    /**
     * Valide un décimal positif (ex: montant paiement).
     */
    public static function positiveDecimal($value): ?float
    {
        $f = filter_var($value, FILTER_VALIDATE_FLOAT);
        return ($f !== false && $f >= 0) ? round($f, 2) : null;
    }
}
