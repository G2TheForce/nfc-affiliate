<?php

namespace App\Domain\Cards;

use App\Models\CardProfile;

/** vCard 3.0 for "Save contact", which iPhone and Android both import. */
class VCard
{
    public function build(CardProfile $profile, CardProfilePresenter $presenter): string
    {
        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'FN:'.$this->escape($profile->display_name),
            'N:'.$this->escape($profile->display_name).';;;;',
        ];
        if ($profile->company) {
            $lines[] = 'ORG:'.$this->escape($profile->company);
        }
        if ($profile->headline) {
            $lines[] = 'TITLE:'.$this->escape($profile->headline);
        }
        if ($profile->phone) {
            $lines[] = 'TEL;TYPE=CELL:'.$this->escape($profile->phone);
        }
        if ($profile->email) {
            $lines[] = 'EMAIL;TYPE=INTERNET:'.$this->escape($profile->email);
        }
        if ($profile->website) {
            $lines[] = 'URL:'.$this->escape($profile->website);
        }
        $lines[] = 'URL;TYPE=profile:'.$this->escape($presenter->publicUrl($profile));
        foreach ($profile->socials ?? [] as $network => $value) {
            $lines[] = 'X-SOCIALPROFILE;TYPE='.$network.':'.$this->escape($presenter->socialUrl($network, $value));
        }
        if ($profile->location) {
            $lines[] = 'ADR;TYPE=WORK:;;;'.$this->escape($profile->location).';;;';
        }
        if ($profile->bio) {
            $lines[] = 'NOTE:'.$this->escape($profile->bio);
        }
        $lines[] = 'END:VCARD';

        return implode("\r\n", $lines)."\r\n";
    }

    /** Escapes text values (RFC 2426) and drops anything that could start a new property line. */
    private function escape(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\\,', '\\n'], $value);
    }
}
