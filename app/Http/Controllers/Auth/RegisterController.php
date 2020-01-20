<?php

namespace App\Http\Controllers\Auth;

use App\Forms\RegisterForm;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Auth\RedirectsUsers;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Kris\LaravelFormBuilder\FormBuilder;
use RuntimeException;

/**
 * Registation controller
 */
class RegisterController extends Controller
{
    private const SESSION_ACCESS = 'onboarding.after-registration';

    use RedirectsUsers;
    use RedirectsToAdminHomeTrait;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest');
    }


    /**
     * Show the application registration form.
     *
     * @return \Illuminate\Http\Response
     */
    public function showRegistrationForm(FormBuilder $formBuilder)
    {
        // Create form
        $form = $formBuilder->create(RegisterForm::class, [
            'method' => 'POST',
            'url' => route('register')
        ]);

        // Make form
        return view('auth.register', compact('form'));
    }

    /**
     * Registers a new user in the system
     *
     * @param FormBuilder $formBuilder
     * @return Illuminate\Routing\Redirector|Illuminate\Http\RedirectResponse
     * @throws BindingResolutionException
     */
    public function register(FormBuilder $formBuilder)
    {
        // Get form
        $form = $formBuilder->create(RegisterForm::class);

        // Or automatically redirect on error. This will throw an HttpResponseException with redirect
        $form->redirectIfNotValid();

        // Get values
        $userValues = $form->getFieldValues();

        // Format some values
        $userValues['alias'] = empty($userValues['alias']) ? null : Str::lower($userValues['alias']);
        $userValues['email'] = Str::lower($userValues['email']);
        $userValues['password'] = Hash::make($userValues['password']);

        // Create a user with the values
        $user = User::create($userValues);

        // Dispatch event
        event(new Registered($user));

        // Log in user
        Auth::guard()->login($user);

        // Flash message
        flash('Je bent nu geregistreerd. Je hebt een mailtje ontvangen om je inschrijving te bevestigen.', 'info');

        // Forward client
        return response()->redirectToRoute('home');
    }

    /**
     * Show welcome response
     *
     * @param Request $request
     * @return Response
     * @throws RuntimeException
     */
    public function afterRegister(Request $request)
    {
        // Remove session flag if continuing
        if ($request->has('continue')) {
            $request->session()->forget(self::SESSION_ACCESS);
        }

        // Show onboarding if allowed
        if ($request->session()->has(self::SESSION_ACCESS)) {
            return view('onboarding.new-account');
        }

        // Redirect to next page otherwise
        return redirect()->intended();
    }
}
