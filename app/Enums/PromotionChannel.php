<?php

namespace App\Enums;

enum PromotionChannel: string
{
    case Website = 'website';
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case TikTok = 'tiktok';
    case YouTube = 'youtube';
    case WhatsApp = 'whatsapp';
    case Viber = 'viber';
    case Telegram = 'telegram';
    case Email = 'email';
    case Offline = 'offline';
    case Other = 'other';
}
