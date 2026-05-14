# VirtualMD Hero Booking Widget

Plugin para renderizar el widget de agendamiento del hero mediante shortcode.

## Shortcodes

- `[virtualmd_hero_booking]`
- `[vm_hero_booking]`

## Configuracion requerida

Las constantes existentes se siguen leyendo desde `wp-config.php`:

- `AMELIA_API_KEY`
- `PAYPAL_CLIENT_ID`
- `PAYPAL_CLIENT_SECRET`
- `PAYPAL_MODE`
- `STRIPE_PUBLIC_KEY`
- `STRIPE_SECRET_KEY`

Stripe usa el vendor incluido en `virtualmd-hero-booking-widget/stripe/vendor/autoload.php`.
