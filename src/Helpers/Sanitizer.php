<?php
// src/Helpers/Sanitizer.php
//
// Centralise le nettoyage / validation des donnees user en entree.
// Vitrine "codage defensif" : tout ce qui arrive du client passe par ici
// avant d'atteindre la BDD.
//
// Conso typique dans un Controller :
//   $title = Sanitizer::text($body['title'], 200);
//   $email = Sanitizer::email($body['email']);
//   $amount = Sanitizer::positiveDecimal($body['amount']);
//   if ($email === null) { http 400 ... }

namespace App\Helpers;

use DateTime;

class Sanitizer
{
    /**
     * Texte libre (noms, commentaires, descriptions). Supprime les balises HTML
     * + echappe les entites + trim + limite la longueur.
     * Defense contre XSS stocke : meme si quelqu'un POSTe <script>, on stocke
     * la version echappee, et React echappera aussi a l'affichage.
     */
    public static function text(?string $value, int $maxLength = 500): string
    {
        if ($value === null) return '';
        $value = strip_tags($value);
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return mb_substr(trim($value), 0, $maxLength);
    }

    /**
     * Email valide ou null. FILTER_SANITIZE_EMAIL vire les caracteres illegaux,
     * FILTER_VALIDATE_EMAIL verifie le format RFC.
     * 2 etapes obligatoires : SANITIZE seul renvoie un truc "propre" mais
     * pas forcement valide.
     */
    public static function email(?string $value): ?string
    {
        if ($value === null) return null;
        $sanitized = filter_var(trim($value), FILTER_SANITIZE_EMAIL);
        return filter_var($sanitized, FILTER_VALIDATE_EMAIL) ? $sanitized : null;
    }

    /** Date au format YYYY-MM-DD, ou null. Refuse "2026-02-30" (parse OK mais resultat KO). */
    public static function date(?string $value): ?string
    {
        if (empty($value)) return null;
        $d = DateTime::createFromFormat('Y-m-d', $value);
        // Le re-format check qu'on retombe pile sur l'entree (rejette le 30 fevrier).
        return ($d && $d->format('Y-m-d') === $value) ? $value : null;
    }

    /** Heure HH:MM ou HH:MM:SS. Regex simple. */
    public static function time(?string $value): ?string
    {
        if (empty($value)) return null;
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
            return $value;
        }
        return null;
    }

    /**
     * Valeur dans une liste autorisee (enum applicatif).
     * ex: Sanitizer::enum($status, ['draft', 'published', 'cancelled']);
     */
    public static function enum(?string $value, array $allowed): ?string
    {
        return in_array($value, $allowed, true) ? $value : null;
    }

    /** Entier dans une plage optionnelle. Null si non parsable ou hors plage. */
    public static function integer($value, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false) return null;
        return ($int >= $min && $int <= $max) ? $int : null;
    }

    /**
     * Decimal positif a 2 chiffres apres la virgule (ex: montant paiement).
     * Le trigger BDD verifiera aussi amount > 0 (defense en profondeur).
     */
    public static function positiveDecimal($value): ?float
    {
        $f = filter_var($value, FILTER_VALIDATE_FLOAT);
        return ($f !== false && $f >= 0) ? round($f, 2) : null;
    }
}
