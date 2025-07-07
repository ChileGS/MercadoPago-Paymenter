<!DOCTYPE html>
<html lang="es-CL">
<head>
    <meta charset="UTF-8">
    <title>Pagar con MercadoPago</title>
    <style>
      /* … tu CSS … */
    </style>
</head>
<body>

  @if(!empty($preferenceId))
    <div class="cho-container"></div>

    <!-- SDK JS v2 de Checkout Pro para MercadoPago Chile -->
    <script src="https://sdk.mercadopago.com/js/v2?site_id={{ strtoupper($siteId) }}"></script>  <!-- Chile :contentReference[oaicite:1]{index=1} -->
    <script>
      // Inicializa con tu Public Key
      const mp = new MercadoPago("{{ $publicKey }}", { locale: 'es-CL' });

      mp.checkout({
        preference: {
          id: '{{ $preferenceId }}'
        },
        render: {
          container: '.cho-container',
          label: 'Pagar con MercadoPago'
        }
      });
    </script>
  @else
    <p style="color: red;">No se pudo generar el pago. Intenta de nuevo.</p>
  @endif

  <div class="footer">
    Pago procesado por Mercado Pago.
  </div>

</body>
</html>
