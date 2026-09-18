import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router';
import { ApiError, ValidationError } from '../lib/api';
import { useAuth } from '../lib/auth';
import { AuthShell, Field, FormError, SubmitButton } from '../components/layout/AuthShell';

export default function Register() {
    const { register } = useAuth();
    const navigate = useNavigate();

    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
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
            await register({
                name,
                email,
                password,
                password_confirmation: confirmation,
            });
            void navigate('/', { replace: true });
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
            title="Creer un compte"
            subtitle="Vos documents restent sur votre espace."
            footer={
                <>
                    Deja inscrit ?{' '}
                    <Link to="/login" className="font-medium text-accent">
                        Se connecter
                    </Link>
                </>
            }
        >
            <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
                <FormError message={message} />

                <Field
                    id="name"
                    label="Nom"
                    type="text"
                    name="name"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    autoComplete="name"
                    required
                    error={errors.name?.[0]}
                />

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
                    autoComplete="new-password"
                    required
                    error={errors.password?.[0]}
                />

                <Field
                    id="password_confirmation"
                    label="Confirmer le mot de passe"
                    type="password"
                    name="password_confirmation"
                    value={confirmation}
                    onChange={(e) => setConfirmation(e.target.value)}
                    autoComplete="new-password"
                    required
                    error={errors.password_confirmation?.[0]}
                />

                <SubmitButton pending={pending}>Creer mon compte</SubmitButton>
            </form>
        </AuthShell>
    );
}
