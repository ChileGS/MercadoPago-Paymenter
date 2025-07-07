<?php

namespace Paymenter\Extensions\Gateways\MercadoPago;

use App\Classes\Extension\Gateway;
use App\Helpers\ExtensionHelper;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;

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
        ];

        $response = Http::withToken($this->config('access_token'))
            ->post($this->apiUrl() . '/checkout/preferences', $requestBody);

        if ($response->failed()) {
            abort(500, 'Error al crear preferencia: ' . $response->body());
        }

        $data = $response->json();
        $preferenceId = $data['id'] ?? null;
        $initPoint   = $data['init_point'];
        
        return redirect()->away($initPoint);
    }

    public function webhook(Request $request)
    {
        // Captura do ID do pagamento tanto pela URL quanto pelo corpo
        $topic = $request->query('topic') ?? $request->query('type') ?? $request->input('type');
        $id = $request->query('id') ?? $request->input('data.id');

        if (!$topic || !$id) {
            return response()->json(['status' => 'error', 'message' => 'Invalid webhook data'], 400);
        }

        // Consulta o pagamento
        $payment = Http::withToken($this->config('access_token'))
            ->get($this->apiUrl() . "/v1/payments/{$id}");

        if ($payment->failed()) {
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve payment'], 500);
        }

        $paymentData = $payment->json();
        $invoiceId = $paymentData['external_reference'] ?? null;

        if ($paymentData['status'] === 'approved' && $invoiceId) {
            ExtensionHelper::addPayment(
                $invoiceId,
                'MercadoPago',
                $paymentData['transaction_amount'],
                0,
                $paymentData['id']
            );
        }

        return response()->json(['status' => 'success']);
    }

}
