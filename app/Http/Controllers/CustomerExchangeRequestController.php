<?php

namespace App\Http\Controllers;

use App\Exceptions\StaleSellQuoteException;
use App\Models\ExchangeRequest;
use App\Models\User;
use App\Services\ExchangeRateResolver;
use App\Services\SellRequestService;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CustomerExchangeRequestController extends Controller
{
    public function create(Request $request, SellRequestService $sellRequests): View
    {
        $user = $this->customer($request);

        return view('pages.exchange-sell', [
            'user' => $user,
            'availableBalance' => $sellRequests->availableBalance($user),
            'submissionKey' => (string) Str::uuid(),
        ]);
    }

    public function estimate(Request $request, ExchangeRateResolver $rates): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,11})(?:\.\d{1,8})?$/'],
        ]);
        if (Decimal::compare($validated['amount'], '0', 8) <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Enter a USDT amount greater than zero.',
            ]);
        }

        $plan = $rates->forAmount($validated['amount']);

        return response()->json([
            'plan' => $plan->name,
            'rate' => $plan->formattedRate(),
            'estimated_inr' => $rates->inrAmount($validated['amount'], (string) $plan->rate),
        ]);
    }

    public function quote(Request $request, SellRequestService $sellRequests): View|RedirectResponse
    {
        $user = $this->customer($request);
        $validator = Validator::make($request->all(), [
            'submission_key' => ['required', 'uuid'],
            'amount' => ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,11})(?:\.\d{1,8})?$/'],
        ]);

        if ($validator->fails()) {
            return redirect()->route('exchange.sell')
                ->withErrors($validator)
                ->withInput($request->only(['amount']));
        }

        $validated = $validator->validated();
        if (Decimal::compare($validated['amount'], '0', 8) <= 0) {
            return redirect()->route('exchange.sell')
                ->withErrors(['amount' => 'Enter a USDT amount greater than zero.'])
                ->withInput($validated);
        }

        try {
            $quote = $sellRequests->quote($user, $validated['amount']);
        } catch (ValidationException $exception) {
            return redirect()->route('exchange.sell')
                ->withErrors($exception->errors())
                ->withInput($validated);
        }

        return view('pages.exchange-sell-confirm', [
            'user' => $user,
            'quote' => $quote,
            'submissionKey' => $validated['submission_key'],
            'hasWalletPin' => $user->hasWalletTransactionPin(),
            'maskedEmail' => $this->maskedEmail($user->email),
        ]);
    }

    public function store(Request $request, SellRequestService $sellRequests): RedirectResponse|Response
    {
        $user = $this->customer($request);
        $transactionPin = (string) $request->input('wallet_transaction_pin', '');
        $request->request->remove('wallet_transaction_pin');
        $validator = Validator::make($request->all(), [
            'submission_key' => ['required', 'uuid'],
            'quote_token' => ['required', 'string', 'max:5000'],
            'amount' => ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,11})(?:\.\d{1,8})?$/'],
        ]);
        $pinValidator = Validator::make(['wallet_transaction_pin' => $transactionPin], [
            'wallet_transaction_pin' => ['required', 'digits:4'],
        ]);

        if ($validator->fails()) {
            return redirect()->route('exchange.sell')
                ->withErrors($validator)
                ->withInput($request->only(['amount']));
        }

        $validated = $validator->validated();
        if ($pinValidator->fails()) {
            try {
                $quote = $sellRequests->quote($user, $validated['amount']);
            } catch (ValidationException $quoteException) {
                return redirect()->route('exchange.sell')
                    ->withErrors($quoteException->errors())
                    ->withInput(['amount' => $validated['amount']]);
            }

            return response(
                view('pages.exchange-sell-confirm', [
                    'user' => $user,
                    'quote' => $quote,
                    'submissionKey' => $validated['submission_key'],
                    'hasWalletPin' => $user->hasWalletTransactionPin(),
                    'maskedEmail' => $this->maskedEmail($user->email),
                ])->withErrors($pinValidator->errors()),
                422,
            );
        }

        try {
            $exchange = $sellRequests->submit(
                $user,
                $validated['amount'],
                $validated['submission_key'],
                $validated['quote_token'],
                $transactionPin,
                $request->ip(),
            );
        } catch (StaleSellQuoteException) {
            return redirect()->route('exchange.sell')
                ->withInput([
                    'amount' => $validated['amount'],
                ])
                ->with('rate_notice', 'The rate changed before confirmation. Review the updated request before submitting.');
        } catch (ValidationException $exception) {
            try {
                $quote = $sellRequests->quote($user, $validated['amount']);
            } catch (ValidationException $quoteException) {
                return redirect()->route('exchange.sell')
                    ->withErrors($quoteException->errors())
                    ->withInput(['amount' => $validated['amount']]);
            }

            return response(
                view('pages.exchange-sell-confirm', [
                    'user' => $user,
                    'quote' => $quote,
                    'submissionKey' => $validated['submission_key'],
                    'hasWalletPin' => $user->hasWalletTransactionPin(),
                    'maskedEmail' => $this->maskedEmail($user->email),
                ])->withErrors($exception->errors()),
                422,
            );
        }

        return redirect()->route('exchange.requests.show', $exchange)
            ->with('sell_request_submitted', true);
    }

    public function show(Request $request, ExchangeRequest $exchangeRequest): View
    {
        $user = $this->customer($request);
        abort_unless($exchangeRequest->user_id === $user->id, 404);

        return view('pages.profile-exchange-show', [
            'user' => $user,
            'exchange' => $exchangeRequest,
        ]);
    }

    private function customer(Request $request): User
    {
        $user = $request->user('web');
        abort_unless($user?->role === 'user', 403);

        return $user;
    }

    private function maskedEmail(string $email): string
    {
        [$name, $domain] = explode('@', $email, 2);

        return mb_substr($name, 0, 1).str_repeat('*', min(5, max(3, mb_strlen($name) - 1))).'@'.$domain;
    }
}
