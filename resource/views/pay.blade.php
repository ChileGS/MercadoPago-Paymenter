@if($preferenceId)
    <div class="cho-container"></div>

    <!-- SDK JS de MercadoPago para Checkout Pro -->
    <script src="https://sdk.mercadopago.com/js/v2?site_id={{ strtolower($this->config('site_id') ?? 'MLC') }}"></script>

    <script>
        // Inicializa MercadoPago con tu Public Key y locale
        const mp = new MercadoPago("{{ $publicKey }}", { locale: 'es-CL' });

        mp.checkout({
            preference: {
                id: '{{ $preferenceId }}'
            },
            render: {
                container: '.cho-container',    // Clase CSS del contenedor
                label: 'Pagar con MercadoPago'  // Texto del botón
            }
        });
    </script>
@else
    <p style="color: red;">No se pudo generar el pago. Intenta de nuevo.</p>
@endif
