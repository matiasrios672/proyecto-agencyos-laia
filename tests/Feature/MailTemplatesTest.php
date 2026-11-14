<?php

use App\Mail\AnnouncementMail;
use App\Mail\TemplatePreviewMail;
use App\Models\Announcement;
use App\Models\Client;
use App\Models\MailTemplate;
use App\Support\MailTemplates;
use App\Support\Settings;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->admin = userWithRole('sysadmin');
});

it('solo el sysadmin accede a las plantillas de correo', function (string $role) {
    $user = userWithRole($role);

    $this->actingAs($user)->get('/admin/correos')->assertForbidden();
    $this->actingAs($user)->get('/admin/correos/quote.sent')->assertForbidden();
    $this->actingAs($user)->post('/admin/correos', ['label' => 'X'])->assertForbidden();
    $this->actingAs($user)->put('/admin/correos/quote.sent', ['subject' => 'a', 'body' => 'b'])->assertForbidden();
})->with(['dueno', 'empleado']);

it('lista las plantillas del sistema agrupadas y abre cada editor', function () {
    $this->actingAs($this->admin)->get('/admin/correos')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/MailTemplates', false)->has('groups.Clientes')->has('groups.Seguridad y accesos'));

    foreach (array_keys(MailTemplates::definitions()) as $key) {
        $this->actingAs($this->admin)->get("/admin/correos/{$key}")->assertOk();
    }
});

describe('edición de plantillas del sistema', function () {
    it('guarda el texto editado y lo usa al armar el correo', function () {
        $this->actingAs($this->admin)->put('/admin/correos/quote.sent', [
            'subject' => 'Tu propuesta {numero}',
            'body' => "# Buenas, {nombre}\n\nTotal **{total}**.",
        ])->assertRedirect();

        $rendered = MailTemplates::render('quote.sent', ['nombre' => 'Ana', 'numero' => 'P-1', 'total' => 'ARS 10', 'url' => 'https://x.test']);
        expect($rendered['subject'])->toBe('Tu propuesta P-1')
            ->and($rendered['body'])->toContain('Buenas, Ana');
    });

    it('si queda igual al original no guarda nada, y restaurar vuelve al texto original', function () {
        $def = MailTemplates::definition('quote.sent');
        $this->actingAs($this->admin)->put('/admin/correos/quote.sent', ['subject' => $def['subject'], 'body' => $def['body']]);
        expect(MailTemplates::current('quote.sent')['customized'])->toBeFalse();

        $this->actingAs($this->admin)->put('/admin/correos/quote.sent', ['subject' => 'Otro', 'body' => 'Otro cuerpo']);
        expect(MailTemplates::current('quote.sent')['customized'])->toBeTrue();

        $this->actingAs($this->admin)->post('/admin/correos/quote.sent/restaurar')->assertRedirect();
        expect(MailTemplates::current('quote.sent'))->toMatchArray(['subject' => $def['subject'], 'customized' => false]);
    });

    it('escapa el HTML de lo que escriben las personas y deja visible una variable desconocida', function () {
        $html = MailTemplates::html('announcement.sent', ['nombre' => '<script>alert(1)</script>', 'asunto' => 'A', 'mensaje' => 'hola'], 'S', "Hola {nombre} {inventada}");

        expect($html)->not->toContain('<script>alert(1)</script>')->toContain('{inventada}');
    });

    it('un botón con URL inválida no arma un enlace roto', function () {
        $html = MailTemplates::html('announcement.sent', ['nombre' => 'A', 'asunto' => 'A', 'mensaje' => 'm'], 'S', '[[boton: Entrar | javascript:alert(1)]]');

        expect($html)->not->toContain('javascript:');
    });

    it('los correos obligatorios no se pueden desactivar; los demás sí', function () {
        $this->actingAs($this->admin)->post('/admin/correos/auth.invitation/estado', ['enabled' => false])->assertSessionHasErrors('enabled');
        expect(MailTemplates::enabled('auth.invitation'))->toBeTrue();

        $this->actingAs($this->admin)->post('/admin/correos/quote.sent/estado', ['enabled' => false])->assertRedirect();
        expect(MailTemplates::enabled('quote.sent'))->toBeFalse();
    });

    it('una plantilla desconocida da 404', function () {
        $this->actingAs($this->admin)->get('/admin/correos/no.existe')->assertNotFound();
    });
});

describe('diseño con la marca', function () {
    it('el correo incluye los datos de contacto de Laia configurados', function () {
        Settings::set('brand.email', 'hola@laia.test');
        Settings::set('brand.phone', '+54 9 342 555-1234');

        $html = MailTemplates::html('announcement.sent', MailTemplates::sampleVars('announcement.sent'));

        expect($html)->toContain('hola@laia.test')->toContain('+54 9 342 555-1234');
    });
});

describe('vista previa y correo de prueba', function () {
    it('la vista previa devuelve el HTML sin guardar nada', function () {
        $response = $this->actingAs($this->admin)->postJson('/admin/correos/quote.sent/vista-previa', ['subject' => 'Asunto {numero}', 'body' => 'Hola {nombre}'])
            ->assertOk()->assertJsonPath('subject', 'Asunto P-2026-0042');

        expect($response->json('html'))->toContain('Hola Camila');

        expect(MailTemplate::count())->toBe(0);
    });

    it('envía el correo de prueba a quien está logueado', function () {
        Mail::fake();

        $this->actingAs($this->admin)->post('/admin/correos/quote.sent/prueba', [])->assertRedirect();

        Mail::assertSent(TemplatePreviewMail::class, fn ($m) => $m->hasTo($this->admin->email));
    });
});

describe('plantillas propias', function () {
    it('crea una plantilla con texto inicial y abre el editor', function () {
        $this->actingAs($this->admin)->post('/admin/correos', ['label' => 'Promo de verano', 'description' => 'Ofertas'])
            ->assertRedirect('/admin/correos/custom.promo-de-verano');

        $current = MailTemplates::current('custom.promo-de-verano');
        expect(MailTemplates::definition('custom.promo-de-verano'))->toMatchArray(['label' => 'Promo de verano', 'group' => 'Personalizadas', 'custom' => true])
            ->and($current['body'])->toContain('{mensaje}');
        $this->actingAs($this->admin)->get('/admin/correos/custom.promo-de-verano')->assertOk();
    });

    it('dos con el mismo nombre no se pisan', function () {
        $this->actingAs($this->admin)->post('/admin/correos', ['label' => 'Promo']);
        $this->actingAs($this->admin)->post('/admin/correos', ['label' => 'Promo']);

        expect(MailTemplate::where('is_custom', true)->pluck('key')->all())->toBe(['custom.promo', 'custom.promo-2']);
    });

    it('edita nombre, asunto y cuerpo directamente (no hay original al que volver)', function () {
        $this->actingAs($this->admin)->post('/admin/correos', ['label' => 'Promo']);

        $this->actingAs($this->admin)->put('/admin/correos/custom.promo', ['subject' => 'Súper {asunto}', 'body' => 'Hola {nombre}', 'label' => 'Promo 2'])->assertRedirect();
        expect(MailTemplates::definition('custom.promo'))->toMatchArray(['label' => 'Promo 2', 'subject' => 'Súper {asunto}', 'body' => 'Hola {nombre}']);

        $this->actingAs($this->admin)->post('/admin/correos/custom.promo/restaurar')->assertSessionHasErrors('reset');
    });

    it('se elimina y los comunicados que la usaban vuelven a la estándar', function () {
        $this->actingAs($this->admin)->post('/admin/correos', ['label' => 'Promo']);
        $a = Announcement::create(['subject' => 'x', 'body' => 'y', 'audience' => 'todos', 'status' => 'borrador', 'template_key' => 'custom.promo', 'created_by' => $this->admin->id]);

        $this->actingAs($this->admin)->delete('/admin/correos/custom.promo')->assertRedirect('/admin/correos');

        expect(MailTemplate::where('key', 'custom.promo')->exists())->toBeFalse()
            ->and($a->fresh()->template_key)->toBeNull()
            ->and(MailTemplates::exists('custom.promo'))->toBeFalse();
    });

    it('las plantillas del sistema no se pueden eliminar', function () {
        $this->actingAs($this->admin)->delete('/admin/correos/quote.sent')->assertNotFound();
    });

    it('un comunicado se envía con la plantilla propia elegida', function () {
        Mail::fake();
        $this->actingAs($this->admin)->post('/admin/correos', ['label' => 'Promo']);
        $this->actingAs($this->admin)->put('/admin/correos/custom.promo', ['subject' => 'PROMO: {asunto}', 'body' => 'Mirá esto, {nombre}: {mensaje}']);
        Client::factory()->create(['email' => 'a@cliente.com', 'trade_name' => null]);

        $this->actingAs($this->admin)->post('/comunicados', [
            'subject' => 'Verano', 'body' => 'Descuentos', 'audience' => 'todos', 'template_key' => 'custom.promo', 'send' => true,
        ])->assertRedirect();

        Mail::assertQueued(AnnouncementMail::class, function (AnnouncementMail $m) {
            expect($m->envelope()->subject)->toBe('PROMO: Verano');

            return $m->hasTo('a@cliente.com');
        });
    });

    it('si la plantilla elegida está en desuso, el comunicado cae en la estándar', function () {
        $a = new Announcement(['template_key' => 'custom.borrada']);

        expect(AnnouncementMail::keyFor($a))->toBe('announcement.sent');
    });
});
