<?php

use Illuminate\Support\Facades\Schedule;

// Finanzas: se corren todos los días en horario argentino (el servicio `worker` ejecuta schedule:work).
Schedule::command('finance:generate-invoices')
    ->dailyAt('07:30')->timezone('America/Argentina/Buenos_Aires')->withoutOverlapping();

Schedule::command('finance:send-reminders')
    ->dailyAt('09:00')->timezone('America/Argentina/Buenos_Aires')->withoutOverlapping();

// LaiaTime: suspende por mora y reactiva a quienes regularizaron (cada hora).
Schedule::command('laiatime:sync')->hourly()->withoutOverlapping();
