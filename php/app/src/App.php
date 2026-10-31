<?php

declare(strict_types=1);

namespace Bwc;

use Bwc\Calendar\CalendarClient;
use Bwc\Calendar\GoogleAuth;
use Bwc\Calendar\GoogleCalendar;
use Bwc\Mail\DemoMailer;
use Bwc\Mail\Mailer;
use Bwc\Mail\ResendMailer;
use Bwc\Mail\SmtpMailer;

/** Assemble les services selon la configuration. */
final class App
{
    private static ?BookingStore $store = null;
    private static ?Mailer $mailer = null;
    private static ?CalendarClient $calendar = null;
    private static bool $calendarBuilt = false;

    public static function config(): Config
    {
        return Config::get();
    }

    public static function store(): BookingStore
    {
        return self::$store ??= new BookingStore(Database::pdo());
    }

    public static function calendar(): ?CalendarClient
    {
        if (!self::$calendarBuilt) {
            self::$calendarBuilt = true;
            $config = self::config();
            if ($config->googleEnabled()) {
                $google = $config->google();
                self::$calendar = new GoogleCalendar($google['calendar_id'], new GoogleAuth($google));
            }
        }
        return self::$calendar;
    }

    public static function mailer(): Mailer
    {
        if (self::$mailer === null) {
            $config = self::config();
            $mail = $config->mail();
            self::$mailer = match ($mail['mode']) {
                'smtp' => new SmtpMailer($mail['smtp'], $mail['from']),
                'resend' => new ResendMailer($mail['resend_api_key'], $mail['from']),
                default => new DemoMailer($config->storage('demo-emails')),
            };
        }
        return self::$mailer;
    }

    public static function notifications(): Notifications
    {
        return new Notifications(self::store(), self::mailer(), self::calendar(), self::config());
    }

    public static function bookings(): BookingService
    {
        return new BookingService(self::config(), self::store(), self::calendar(), self::notifications());
    }
}
