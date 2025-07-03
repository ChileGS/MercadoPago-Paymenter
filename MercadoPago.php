<?php

namespace Paymenter\Extensions\Gateways\MercadoPago;

use App\Classes\Extension\Gateway;
use App\Helpers\ExtensionHelper;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;


use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;

class MercadoPago extends Gateway
{
    public function boot()
    {
        require __DIR__ . '/routes.php';
        View::addNamespace('gateways.mercadopago', __DIR__ . '/resources/views');
    }

    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'access_token',
                'label' => 'Access Token',
                'type' => 'text',
                'description' => 'Find your Access Token in your Mercado Pago dashboard.',
                'required' => true,
            ],
            [
                'name' => 'public_key',
                'label' => 'Public Key',
                'type' => 'text',
                'description' => 'Your Mercado Pago Public Key.',
                'required' => true,
            ],
            [
                'name' => 'notification_secret',
                'label' => 'Notification Secret',
                'type' => 'text',
                'description' => 'Your Mercado Pago Notification Secret.',
                'required' => false,
            ],
            [
                'name' => 'site_id',
                'label' => 'MP Location',
                'type' => 'select',
                'default' => '1',
                'description' => 'Mercado Pago Location.',
                'required' => true,
                'options' => [
                    'mla' => 'Argentina',
                    'mlb' => 'Brasil',
                    'mlc' => 'Chile',
                    'mlu' => 'Uruguay',
                    'mco' => 'Colombia',
                    'mlv' => 'Venezuela',
                    'mpe' => 'Perú',
                    'mlm' => 'México',
                ],
            ],
            [
                'name' => 'test_mode',
                'label' => 'Test Mode',
                'type' => 'checkbox',
                'description' => 'Enable sandbox/test mode',
                'required' => false,
            ],
        ];
    }

    protected function apiUrl(): string
    {
        // MercadoPago uses the same url for Testing mode and Prod.
        return 'https://api.mercadopago.com';
    }

    public function pay($invoice, $total)
    {
        MercadoPagoConfig::setAccessToken($this->config('access_token'));

        $client = new PreferenceClient();

        $requestBody = [
            "items" => $invoice->items->map(function ($item) use ($invoice) {
                return [
                    "title" => $item->reference->name ?? 'Product',
                    "quantity" => $item->quantity,
                    "unit_price" => floatval($item->reference->price ?? 0),
                    "currency_id" => $invoice->currency_code,
                ];
            })->toArray(),
            "back_urls" => [
                "success" => route('invoices.show', ['invoice' => $invoice->id]),
                "failure" => route('invoices.show', ['invoice' => $invoice->id]),
                "pending" => route('invoices.show', ['invoice' => $invoice->id]),
            ],
            "auto_return" => "approved",
            "notification_url" => route('extensions.gateways.mercadopago.webhook'),
            "external_reference" => (string)$invoice->id,
            "site_id" => strtoupper($this->config('site_id')) ?: 'MLC',
            "payer" => [
                'email' => $invoice->user->email,
                'name' => $invoice->user->name(),
            ]
        ];

        try {

            $resource = $client->create($requestBody);
            $response = $resource->getResponse();

            if ($response->getStatusCode() !== 201) {
                abort(500, 'Error al crear preferencia: ' . print_r($response->getContent(), true));
            }

            $preferenceId = $resource->id;

        } catch (MPApiException $e) {
            $resp = $e->getApiResponse();
            abort(500, "MP API Error ({$resp->getStatusCode()}): " . print_r($resp->getContent(), true));
        }

        return view('gateways.mercadopago::pay', [
            'invoice' => $invoice,
            'total' => $total,
            'preferenceId' => $preferenceId,
            'publicKey' => $this->config('public_key'),
        ]);
    }

    public function webhook(Request $request)
    {
        // 2. Verificación opcional de firma
        $secret = $this->config('notification_secret');

        if (!empty($secret)) {
            $sig    = $request->header('X-MP-Signature', '');
            $hmac   = hash_hmac('sha256', $request->getContent(), $secret);
            if (!hash_equals($hmac, $sig)) {
                return response()->json(['status'=>'error','message'=>'Invalid signature'], 401);
            }
        }

        // 3. Extraer ID de pago
        $id = $request->query('id') ?? $request->input('data.id');
        if (!$id) {
            return response()->json(['status'=>'error','message'=>'Invalid webhook data'], 400);
        }

        // 4. Traer datos del pago con el SDK
        try {
            $client    = new PaymentClient();
            $resource  = $client->get(['payment_id' => $id]);
            $response  = $resource->getResponse();

            if ($response->getStatusCode() !== 200) {
                return response()->json(
                    ['status'=>'error','message'=>'Failed to retrieve payment'],
                    500
                );
            }

            $paymentData = $response->getContent();

        } catch (MPApiException $e) {
            $apiResp = $e->getApiResponse();
            return response()->json(
                ['status'=>'error','message'=>"MP API Error: {$apiResp->getStatusCode()}"],
                500
            );
        }

        // 5. Procesar el pago aprobado
        if (($paymentData['status'] ?? '') === 'approved'
            && ($invoiceId = $paymentData['external_reference'] ?? null)
        ) {
            ExtensionHelper::addPayment(
                $invoiceId,
                'MercadoPago',
                $paymentData['transaction_amount'],
                0,
                $paymentData['id']
            );
        }

        return response()->json(['status'=>'success']);
    }
}
