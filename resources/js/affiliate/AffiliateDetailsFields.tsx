import { Field, TextArea } from '@/components/ui';
import { CHANNELS } from '@/lib/format';

export interface DetailsForm {
    display_name: string;
    country_code: string;
    city: string;
    address_line: string;
    promotion_channels: string[];
    website_urls: string;
    audience_description: string;
}

export const emptyDetails: DetailsForm = {
    display_name: '',
    country_code: 'NP',
    city: '',
    address_line: '',
    promotion_channels: [],
    website_urls: '',
    audience_description: '',
};

export function toPayload(form: DetailsForm) {
    return {
        ...form,
        website_urls: form.website_urls
            .split('\n')
            .map((u) => u.trim())
            .filter(Boolean),
        city: form.city || null,
        address_line: form.address_line || null,
        audience_description: form.audience_description || null,
    };
}

/** Field errors from the API arrive as e.g. `website_urls.1`; show them on the parent field. */
function firstError(errors: Record<string, string>, key: string) {
    return errors[key] ?? Object.entries(errors).find(([k]) => k.startsWith(`${key}.`))?.[1];
}

export function AffiliateDetailsFields({
    form,
    setForm,
    errors,
}: {
    form: DetailsForm;
    setForm: (f: DetailsForm) => void;
    errors: Record<string, string>;
}) {
    const toggle = (value: string) =>
        setForm({
            ...form,
            promotion_channels: form.promotion_channels.includes(value)
                ? form.promotion_channels.filter((c) => c !== value)
                : [...form.promotion_channels, value],
        });

    const channelError = firstError(errors, 'promotion_channels');

    return (
        <div className="space-y-4">
            <Field
                label="Public display name"
                hint="Shown on your referral links. Can be your name, brand or page name."
                value={form.display_name}
                onChange={(e) => setForm({ ...form, display_name: e.target.value })}
                error={errors.display_name}
                required
            />
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <Field
                    label="Country code"
                    maxLength={2}
                    value={form.country_code}
                    onChange={(e) => setForm({ ...form, country_code: e.target.value.toUpperCase() })}
                    error={errors.country_code}
                />
                <div className="sm:col-span-2">
                    <Field label="City (optional)" value={form.city} onChange={(e) => setForm({ ...form, city: e.target.value })} error={errors.city} />
                </div>
            </div>
            <fieldset>
                <legend className="text-sm font-medium text-ink">How will you promote our products?</legend>
                <div className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                    {CHANNELS.map((c) => (
                        <label key={c.value} className="flex min-h-11 items-center gap-2 rounded-lg border border-line px-3 text-sm">
                            <input type="checkbox" className="h-4 w-4" checked={form.promotion_channels.includes(c.value)} onChange={() => toggle(c.value)} />
                            {c.label}
                        </label>
                    ))}
                </div>
                {channelError && <p className="mt-1 text-sm text-danger">{channelError}</p>}
            </fieldset>
            <TextArea
                label="Website or social media links (optional)"
                hint="One link per line, starting with https://"
                value={form.website_urls}
                onChange={(e) => setForm({ ...form, website_urls: e.target.value })}
                error={firstError(errors, 'website_urls')}
            />
            <TextArea
                label="Tell us about your audience (optional)"
                value={form.audience_description}
                onChange={(e) => setForm({ ...form, audience_description: e.target.value })}
                error={errors.audience_description}
            />
        </div>
    );
}
