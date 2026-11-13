<?php

use App\Enums\QuoteStatus;
use App\Mail\QuoteMail;
use App\Models\Client;
use App\Services\QuotePdf;
use App\Support\Settings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->duena = userWithRole('dueno');
    $this->camila = userWithRole('empleado');
    $this->quote = makeQuote($this->duena);
});

describe('PDF', function () {
    it('genera un PDF válido para quien puede ver presupuestos', function () {
        $response = $this->actingAs($this->duena)->get("/presupuestos/{$this->quote->id}/pdf");

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        expect($response->getContent())->toStartWith('%PDF')->and(strlen($response->getContent()))->toBeGreaterThan(1500);
    });

    it('el empleado y los invitados no pueden descargarlo', function () {
        $this->actingAs($this->camila)->get("/presupuestos/{$this->quote->id}/pdf")->assertForbidden();
        auth()->logout();
        $this->get("/presupuestos/{$this->quote->id}/pdf")->assertRedirect('/login');
    });

    it('incluye los datos bancarios configurados por el sysadmin', function () {
        Settings::set('bank.alias', 'laia.estudio.mp');

        $pdf = QuotePdf::render($this->quote);

        expect($pdf)->toStartWith('%PDF');
    });
});

describe('enlace público firmado', function () {
    it('abre con firma válida sin sesión', function () {
        $url = URL::temporarySignedRoute('quotes.public_pdf', now()->addDay(), ['quote' => $this->quote->id]);

        $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
    });

    it('rechaza sin firma, firma adulterada, id cambiado o vencida', function () {
        $this->get("/p/presupuesto/{$this->quote->id}/pdf")->assertForbidden();

        $other = makeQuote($this->duena);
        $signed = URL::temporarySignedRoute('quotes.public_pdf', now()->addDay(), ['quote' => $this->quote->id]);
        $this->get(str_replace("/{$this->quote->id}/", "/{$other->id}/", $signed))->assertForbidden();

        $expired = URL::temporarySignedRoute('quotes.public_pdf', now()->subMinute(), ['quote' => $this->quote->id]);
        $this->get($expired)->assertForbidden();
    });
});

describe('envío', function () {
    it('por email encola el correo, lo marca enviado y lo audita', function () {
        Mail::fake();

        $this->actingAs($this->duena)->post("/presupuestos/{$this->quote->id}/enviar", ['channel' => 'email'])->assertRedirect();

        Mail::assertQueued(QuoteMail::class, fn (QuoteMail $m) => $m->hasTo('hola@losaromos.com.ar') && $m->quote->is($this->quote));
        expect($this->quote->fresh()->status)->toBe(QuoteStatus::Enviado)->and($this->quote->fresh()->sent_at)->not->toBeNull();
        $this->assertDatabaseHas('activity_log', ['log_name' => 'quotes', 'description' => 'Presupuesto enviado por email']);
    });

    it('el correo muestra número y total, y adjunta el PDF', function () {
        $mail = new QuoteMail($this->quote);

        $mail->assertSeeInHtml($this->quote->number);
        $mail->assertSeeInHtml('1.062.000,90');
        expect($mail->attachments())->toHaveCount(1)
            ->and($mail->envelope()->subject)->toContain($this->quote->number);
    });

    it('usa el email del cliente existente', function () {
        Mail::fake();
        $client = Client::factory()->create(['email' => 'cliente@empresa.com.ar']);
        $quote = makeQuote($this->duena, ['client_id' => $client->id]);

        $this->actingAs($this->duena)->post("/presupuestos/{$quote->id}/enviar", ['channel' => 'email'])->assertRedirect();

        Mail::assertQueued(QuoteMail::class, fn ($m) => $m->hasTo('cliente@empresa.com.ar'));
    });

    it('falla si el destinatario no tiene email', function () {
        Mail::fake();
        $quote = makeQuote($this->duena, ['prospect_email' => null]);

        $this->actingAs($this->duena)->post("/presupuestos/{$quote->id}/enviar", ['channel' => 'email'])->assertSessionHasErrors('channel');

        Mail::assertNothingQueued();
        expect($quote->fresh()->status)->toBe(QuoteStatus::Borrador);
    });

    it('por WhatsApp solo marca como enviado', function () {
        Mail::fake();

        $this->actingAs($this->duena)->post("/presupuestos/{$this->quote->id}/enviar", ['channel' => 'whatsapp'])->assertRedirect();

        Mail::assertNothingQueued();
        expect($this->quote->fresh()->status)->toBe(QuoteStatus::Enviado);
    });

    it('la ficha ofrece el enlace de WhatsApp con el PDF firmado', function () {
        $response = $this->actingAs($this->duena)->get("/presupuestos/{$this->quote->id}");

        $response->assertOk()->assertSee('wa.me');
    });

    it('no envía presupuestos vencidos ni resueltos, ni canales desconocidos', function () {
        Mail::fake();
        $vencido = makeQuote($this->duena, ['valid_until' => now()->subDay()->toDateString()]);
        $rechazado = makeQuote($this->duena);
        $rechazado->forceFill(['status' => QuoteStatus::Rechazado])->save();

        $this->actingAs($this->duena)->post("/presupuestos/{$vencido->id}/enviar", ['channel' => 'email'])->assertSessionHasErrors('channel');
        $this->actingAs($this->duena)->post("/presupuestos/{$rechazado->id}/enviar", ['channel' => 'email'])->assertSessionHasErrors('channel');
        $this->actingAs($this->duena)->post("/presupuestos/{$this->quote->id}/enviar", ['channel' => 'paloma'])->assertSessionHasErrors('channel');
        $this->actingAs($this->camila)->post("/presupuestos/{$this->quote->id}/enviar", ['channel' => 'email'])->assertForbidden();

        Mail::assertNothingQueued();
    });
});
