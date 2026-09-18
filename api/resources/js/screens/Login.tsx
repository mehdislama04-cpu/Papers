import { useState, type FormEvent } from 'react';
import { Link, useLocation, useNavigate } from 'react-router';
import { ApiError, ValidationError } from '../lib/api';
import { useAuth } from '../lib/auth';
import { AuthShell, Field, FormError, SubmitButton } from '../components/layout/AuthShell';

/**
 * Connexion par formulaire natif, dans l'app.
 *
 * Pas d'OAuth par redirection : en mode standalone le stockage est isole de
 * Safari, l'aller-retour reviendrait dans Safari et la session ne serait
 * jamais vue par l'app (boucle de login infinie).
 */
export default function Login() {
    const { login } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();
    const from = (location.state as { from?: string } | null)?.from ?? '/';

    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [pending, setPending] = useState(false);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [message, setMessage] = useState<string | null>(null);

    async function onSubmit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (pending) return;

        setPending(true);
        setErrors({});
        setMessage(null);

        try {
            await login({ email, password });
            void navigate(from, { replace: true });
        } catch (error) {
            if (error instanceof ValidationError) {
                setErrors(error.errors);
                setMessage(Object.keys(error.errors).length ? null : error.message);
            } else if (error instanceof ApiError) {
                setMessage(
                    error.isOffline
                        ? 'Connexion impossible : verifiez votre reseau.'
                        : error.message,
                );
            } else {
                setMessage('Une erreur inattendue est survenue.');
            }
        } finally {
            setPending(false);
        }
    }

    return (
        <AuthShell
            title="Papers"
            subtitle="Scannez, triez et analysez vos documents."
            footer={
                <>
                    Pas encore de compte ?{' '}
                    <Link to="/register" className="font-medium text-accent">
                        Creer un compte
                    </Link>
                </>
            }
        >
            <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
                <FormError message={message} />

                <Field
                    id="email"
                    label="Adresse e-mail"
                    type="email"
                    name="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    autoComplete="username"
                    inputMode="email"
                    autoCapitalize="none"
                    autoCorrect="off"
                    spellCheck={false}
                    required
                    error={errors.email?.[0]}
                />

                <Field
                    id="password"
                    label="Mot de passe"
                    type="password"
                    name="password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    autoComplete="current-password"
                    required
                    error={errors.password?.[0]}
                />

                <SubmitButton pending={pending}>Se connecter</SubmitButton>
            </form>
        </AuthShell>
    );
}
