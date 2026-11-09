<?php

namespace App\Http\Controllers;

use App\Domain\ApiProblem;
use App\Http\Middleware\WebDemoSession;
use App\Services\IdempotencyService;
use App\Services\PaymentService;
use App\Services\WebDemoService;
use App\Support\Payload;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class WebDemoController extends Controller
{
    public function __construct(
        private WebDemoService $demo,
        private PaymentService $payments,
        private IdempotencyService $idempotency,
    ) {}

    public function landing(): View
    {
        return view('web.landing');
    }

    public function app(Request $request): View
    {
        Payload::empty($request);

        return view('web.app');
    }

    public function docs(): View
    {
        return view('web.docs');
    }

    public function start(Request $request): JsonResponse
    {
        Payload::empty($request);
        if (WebDemoSession::credential($request) !== null) {
            return response()->json(['redirect' => route('web.app', [], false)]);
        }
        $rateKey = 'web-entry:'.hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($rateKey, 10)) {
            throw new ApiProblem(429, 'RATE_LIMITED', 'Limite de demonstrações atingido. Tente novamente em um minuto.');
        }
        RateLimiter::hit($rateKey, 60);
        $session = $this->demo->provision();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('web_demo', $session);

        return response()->json(['redirect' => route('web.app', [], false)]);
    }

    public function logout(Request $request): JsonResponse
    {
        Payload::empty($request);
        DB::table('demo_credentials')->where('id', $request->attributes->get('web_credential_id'))
            ->where('merchant_id', $request->attributes->get('merchant_id'))->where('role', 'merchant')
            ->whereNull('revoked_at')->update(['revoked_at' => now('UTC')]);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['redirect' => route('web.landing', [], false)]);
    }

    public function overview(Request $request): JsonResponse
    {
        Payload::empty($request);
        $this->queryFields($request, []);

        return response()->json($this->demo->overview($request->attributes->get('merchant_id')));
    }

    public function listing(Request $request): JsonResponse
    {
        Payload::empty($request);
        $this->queryFields($request, ['status', 'search', 'page', 'limit']);
        $status = $request->query('status', 'ALL');
        $search = $request->query('search', '');
        $page = $request->query('page', '1');
        $limit = $request->query('limit', '10');
        if (! is_string($status) || ! in_array($status, ['ALL', 'CREATED', 'AUTHORIZED', 'SETTLED', 'FAILED', 'REFUNDED'], true)) {
            throw new ApiProblem(422, 'VALIDATION_ERROR', 'Filtro de estado inválido.');
        }
        if (! is_string($search) || mb_strlen($search) > 80 || preg_match('/[\x00-\x1F\x7F]/', $search)) {
            throw new ApiProblem(422, 'VALIDATION_ERROR', 'Busca inválida; use até 80 caracteres.');
        }
        foreach (['page' => [$page, 1000000], 'limit' => [$limit, 50]] as [$value, $maximum]) {
            if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^[1-9][0-9]{0,6}$/D', (string) $value) || (int) $value > $maximum) {
                throw new ApiProblem(422, 'VALIDATION_ERROR', 'Paginação inválida.');
            }
        }

        return response()->json($this->demo->listing(
            $request->attributes->get('merchant_id'), $status, trim($search), (int) $page, (int) $limit,
        ));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        Payload::empty($request);
        $this->queryFields($request, []);

        return response()->json($this->demo->detail($request->attributes->get('merchant_id'), strtolower($id)));
    }

    public function create(Request $request): JsonResponse
    {
        $key = Payload::key($request);
        $command = Payload::validate($request, [
            'gross_amount_cents' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'cbs_amount_cents' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'ibs_amount_cents' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'scenario' => ['required', 'string', 'in:approved,declined'],
        ], ['gross_amount_cents', 'cbs_amount_cents', 'ibs_amount_cents']);
        if ($command['cbs_amount_cents'] > $command['gross_amount_cents'] - $command['ibs_amount_cents']) {
            throw new ApiProblem(422, 'VALIDATION_ERROR', 'CBS + IBS não pode superar o bruto.');
        }

        return $this->respond($this->demo->create($request->attributes->get('merchant_id'), $command, $key));
    }

    public function authorize(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, 'authorize_payment');
    }

    public function settle(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, 'settle_payment');
    }

    public function refund(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, 'refund_payment');
    }

    private function transition(Request $request, string $id, string $operation): JsonResponse
    {
        $key = Payload::key($request);
        $command = Payload::empty($request);
        $merchant = $request->attributes->get('merchant_id');
        $id = strtolower($id);
        $this->payments->ownedPayment($id, $merchant);

        return $this->respond($this->idempotency->run(
            $merchant, $operation, $id, $key, $command,
            fn (): array => $this->payments->transition($merchant, $id, $operation),
        ));
    }

    public function examples(Request $request): JsonResponse
    {
        Payload::empty($request);

        return response()->json($this->demo->seedExamples($request->attributes->get('merchant_id')));
    }

    private function respond(array $result): JsonResponse
    {
        return response()->json($result['body'], $result['status'], [
            ...($result['headers'] ?? []), 'Idempotency-Replayed' => $result['replayed'] ? 'true' : 'false',
        ]);
    }

    private function queryFields(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->query()), $allowed)) {
            throw new ApiProblem(422, 'VALIDATION_ERROR', 'Parâmetro de consulta não permitido.');
        }
    }

    public function openApi(): BinaryFileResponse
    {
        return $this->downloadContract('openapi.yaml');
    }

    public function webOpenApi(): BinaryFileResponse
    {
        return $this->downloadContract('openapi-web.yaml');
    }

    private function downloadContract(string $filename): BinaryFileResponse
    {
        $path = realpath(base_path('../../docs/'.$filename));
        abort_if($path === false || ! is_file($path), 404);
        $response = response()->download($path, $filename, [
            'Content-Type' => 'application/yaml',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
