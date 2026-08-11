<?php

namespace Liberu\Ecommerce\PaymentOperations\Livewire;

use Illuminate\Support\ServiceProvider;
use Liberu\Ecommerce\PaymentOperations\Livewire\Components\PayForOrder;
use Liberu\Ecommerce\PaymentOperations\Livewire\Components\PaymentReceipt;
use Liberu\Ecommerce\PaymentOperations\Livewire\Components\SavedInstruments;
use Livewire\Livewire;

/**
 * Registers this package's bounded Livewire namespace.
 *
 * Registered by `ModuleManagerServiceProvider` from `module.json`, never by
 * Composer discovery — the package ships no `extra.laravel.providers`, so
 * installing it boots nothing until a deployment names the module in
 * `MODULES_ENABLED`.
 *
 * Aliases are explicit rather than discovered. A directory scan resolves
 * whatever happens to be on disk, so moving a class or adding one would
 * silently change a public interface; this list *is* the interface, and
 * changing it is a diff somebody reviews.
 */
class PaymentOperationsLivewireServiceProvider extends ServiceProvider
{
    /**
     * The one namespace this package owns, for components, views and
     * translations alike. It drops the `-livewire` suffix and keeps the
     * ownership prefix: it names the bounded context, not the technology
     * presenting it.
     */
    public const NAMESPACE = 'module-ecommerce-payment-operations';

    /**
     * The package's public component surface — the whole of it.
     *
     * Three, and the shortness of the list is the design. Authorising,
     * capturing, voiding and refunding somebody's money are operator concerns
     * and live in `-filament`; an unmatched callback and a reconciliation queue
     * live there too. What is left for a shopper is paying, seeing what happened
     * to their own payment, and taking a saved card off their account.
     *
     * @var array<string, class-string>
     */
    private const COMPONENTS = [
        'pay' => PayForOrder::class,
        'receipt' => PaymentReceipt::class,
        'instruments' => SavedInstruments::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/payment-operations-livewire.php', 'payment-operations-livewire');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', self::NAMESPACE);
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', self::NAMESPACE);

        $aliases = $this->aliases();

        // Two halves of the same registration, and both are needed.
        //
        // `component()` is the name a class reports as — what a rendered
        // component calls itself, and what `Livewire::test(SomeClass::class)`
        // resolves back to. Without it the public name of a component would be
        // derived from wherever its file happens to sit.
        //
        // `resolveMissingComponent()` is the other direction, and it is the one
        // that costs an afternoon if it is missing. Livewire 4's
        // `Finder::resolveClassComponentClassName()` returns null for a
        // `namespace::name` *before* it consults the explicit registry, so
        // `component()` alone never answers one. `addNamespace()` does answer,
        // but it maps one Livewire namespace onto exactly one class namespace,
        // which forecloses a `Pages\` this package may yet want. So the alias
        // table answers instead.
        foreach ($aliases as $alias => $component) {
            Livewire::component($alias, $component);
        }

        Livewire::resolveMissingComponent(
            static fn (string $name): ?string => $aliases[$name] ?? null,
        );

        // Publishing views is how a theme overrides one without forking the
        // package. Translations publish separately, because a deployment that
        // wants its own wording rarely wants its own markup as well — and the
        // wording on a payment page is the part a merchant's compliance team
        // edits.
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/'.self::NAMESPACE),
        ], self::NAMESPACE.'-views');

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/'.self::NAMESPACE),
        ], self::NAMESPACE.'-translations');

        $this->publishes([
            __DIR__.'/../config/payment-operations-livewire.php' => config_path('payment-operations-livewire.php'),
        ], self::NAMESPACE.'-config');
    }

    /**
     * The component table, keyed by the fully qualified alias.
     *
     * @return array<string, class-string>
     */
    public function aliases(): array
    {
        $aliases = [];

        foreach (self::COMPONENTS as $alias => $component) {
            $aliases[self::NAMESPACE.'::'.$alias] = $component;
        }

        return $aliases;
    }
}
