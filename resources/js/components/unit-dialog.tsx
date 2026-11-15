import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { rentModeLabels } from '@/lib/property-labels';
import { type UnitRow } from '@/types';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

interface Props {
    propertyId: number;
    unit: UnitRow | null; // null = adding a new room
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

const blank = { code: '', building: '', floor: '', capacity: '1', rent_mode: 'whole', status: 'available', description: '' };

export default function UnitDialog({ propertyId, unit, open, onOpenChange }: Props) {
    const { data, setData, post, put, processing, errors, clearErrors } = useForm(blank);

    useEffect(() => {
        if (!open) return;
        clearErrors();
        setData(
            unit
                ? {
                      code: unit.code,
                      building: unit.building ?? '',
                      floor: unit.floor ?? '',
                      capacity: String(unit.capacity),
                      rent_mode: unit.rent_mode,
                      status: unit.status,
                      description: unit.description ?? '',
                  }
                : blank,
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, unit?.id]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onOpenChange(false) };
        if (unit) put(route('units.update', unit.id), options);
        else post(route('properties.units.store', propertyId), options);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>{unit ? `Edit room ${unit.code}` : 'Add a room'}</DialogTitle>
                        <DialogDescription>A room can be rented as a whole, or bed by bed.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="unit-code">Room name or number</Label>
                        <Input id="unit-code" value={data.code} onChange={(e) => setData('code', e.target.value.toUpperCase())} placeholder="A101" required />
                        <InputError message={errors.code} />
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div className="grid gap-2">
                            <Label htmlFor="unit-building">Building (optional)</Label>
                            <Input id="unit-building" value={data.building} onChange={(e) => setData('building', e.target.value)} />
                            <InputError message={errors.building} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="unit-floor">Floor (optional)</Label>
                            <Input id="unit-floor" value={data.floor} onChange={(e) => setData('floor', e.target.value)} />
                            <InputError message={errors.floor} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="unit-capacity">How many people can stay?</Label>
                        <Input
                            id="unit-capacity"
                            type="number"
                            inputMode="numeric"
                            min={1}
                            max={50}
                            value={data.capacity}
                            onChange={(e) => setData('capacity', e.target.value)}
                            className="max-w-32"
                            required
                        />
                        <InputError message={errors.capacity} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="unit-mode">How is rent charged?</Label>
                        <Select value={data.rent_mode} onValueChange={(value) => setData('rent_mode', value)}>
                            <SelectTrigger id="unit-mode">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="whole">{rentModeLabels.whole}: one rent for the room</SelectItem>
                                <SelectItem value="per_bed">{rentModeLabels.per_bed}: each bed has its own rent</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError message={errors.rent_mode} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="unit-status">Can people move in?</Label>
                        <Select value={data.status} onValueChange={(value) => setData('status', value)}>
                            <SelectTrigger id="unit-status">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="available">Yes, open for tenants</SelectItem>
                                <SelectItem value="maintenance">No, under repair</SelectItem>
                                <SelectItem value="unavailable">No, not available</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError message={errors.status} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="unit-description">Notes (optional)</Label>
                        <Input id="unit-description" value={data.description} onChange={(e) => setData('description', e.target.value)} />
                        <InputError message={errors.description} />
                    </div>

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="secondary" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>{processing ? 'Saving...' : unit ? 'Save room' : 'Add room'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
