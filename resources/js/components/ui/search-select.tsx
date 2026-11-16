import { cn } from '@/lib/utils';
import { Combobox, ComboboxButton, ComboboxInput, ComboboxOption, ComboboxOptions } from '@headlessui/react';
import { Check, ChevronsUpDown, Search } from 'lucide-react';
import { useMemo, useState } from 'react';

export interface SearchSelectOption {
    value: string;
    label: string;
    /** Smaller grey text after the label (room, account no., amount); also searched. */
    hint?: string;
    disabled?: boolean;
}

interface Props {
    id?: string;
    value: string;
    onChange: (value: string) => void;
    options: SearchSelectOption[];
    placeholder?: string;
    disabled?: boolean;
    className?: string;
    /** Width/size of the field; the list is at least as wide as the field. */
    'aria-label'?: string;
    'aria-describedby'?: string;
}

/**
 * Dropdown you can type into to filter: replaces <select> everywhere. Keyboard: type, arrows, Enter, Esc.
 * The list renders in place (no portal) so it works inside modal dialogs without closing them.
 */
export function SearchSelect({ id, value, onChange, options, placeholder = 'Choose…', disabled, className, ...aria }: Props) {
    const [query, setQuery] = useState('');
    const selected = options.find((o) => o.value === value) ?? null;
    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        return q === '' ? options : options.filter((o) => `${o.label} ${o.hint ?? ''}`.toLowerCase().includes(q));
    }, [options, query]);

    return (
        <Combobox
            value={selected}
            onChange={(o: SearchSelectOption | null) => o && onChange(o.value)}
            onClose={() => setQuery('')}
            disabled={disabled}
            immediate
            by={(a, b) => a?.value === b?.value}
        >
            <div className={cn('relative w-full', className)}>
                <ComboboxInput
                    id={id}
                    {...aria}
                    className="border-input bg-card placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/30 flex h-10 w-full rounded-lg border py-2 pr-8 pl-3 text-sm shadow-xs transition-[border-color,box-shadow] duration-150 outline-none focus-visible:ring-3 disabled:cursor-not-allowed disabled:opacity-50"
                    displayValue={(o: SearchSelectOption | null) => o?.label ?? ''}
                    onChange={(e) => setQuery(e.target.value)}
                    onFocus={(e) => e.currentTarget.select()}
                    placeholder={selected ? selected.label : placeholder}
                    autoComplete="off"
                />
                <ComboboxButton className="text-muted-foreground absolute inset-y-0 right-0 grid w-8 place-items-center" aria-label="Show options">
                    <ChevronsUpDown className="size-4" aria-hidden />
                </ComboboxButton>
                <ComboboxOptions
                    className="bg-popover text-popover-foreground shadow-lift absolute top-full left-0 z-50 mt-1 max-h-64 w-full min-w-48 overflow-auto rounded-lg border p-1 text-sm empty:invisible"
                    modal={false}
                >
                    {options.length > 8 && query === '' && (
                        <div className="text-muted-foreground flex items-center gap-1.5 px-2 py-1 text-xs">
                            <Search className="size-3" aria-hidden /> Type to search {options.length} options
                        </div>
                    )}
                    {filtered.map((o) => (
                        <ComboboxOption
                            key={o.value}
                            value={o}
                            disabled={o.disabled}
                            className="data-[focus]:bg-accent data-[focus]:text-accent-foreground flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 select-none data-[disabled]:cursor-not-allowed data-[disabled]:opacity-50"
                        >
                            <Check className={cn('size-4 shrink-0', o.value === value ? 'opacity-100' : 'opacity-0')} aria-hidden />
                            <span className="min-w-0 flex-1 truncate">{o.label}</span>
                            {o.hint && <span className="text-muted-foreground shrink-0 text-xs">{o.hint}</span>}
                        </ComboboxOption>
                    ))}
                    {filtered.length === 0 && <div className="text-muted-foreground px-2 py-1.5">No matches for “{query}”.</div>}
                </ComboboxOptions>
            </div>
        </Combobox>
    );
}
