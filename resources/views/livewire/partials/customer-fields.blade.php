<div>
    <label for="customer-name" class="mb-1.5 block text-xs font-semibold text-fg-soft">Name</label>
    <input id="customer-name" type="text" wire:model="customerName" class="{{ $inputClasses }}">
    @error('customerName') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
</div>
<div class="grid gap-3 sm:grid-cols-2">
    <div>
        <label for="customer-contact" class="mb-1.5 block text-xs font-semibold text-fg-soft">Contact number</label>
        <input id="customer-contact" type="tel" wire:model="customerContact" class="{{ $inputClasses }}">
        @error('customerContact') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="customer-type" class="mb-1.5 block text-xs font-semibold text-fg-soft">Customer type</label>
        <input id="customer-type" type="text" wire:model="customerType" list="customer-type-options" placeholder="retail, distributor, office…" class="{{ $inputClasses }}">
        <datalist id="customer-type-options"><option value="retail"><option value="distributor"><option value="office"><option value="hospitality"></datalist>
        @error('customerType') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
    </div>
</div>
<div>
    <label for="customer-address" class="mb-1.5 block text-xs font-semibold text-fg-soft">Address</label>
    <input id="customer-address" type="text" wire:model="customerAddress" class="{{ $inputClasses }}">
    @error('customerAddress') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
</div>
<div>
    <label for="customer-payment-terms" class="mb-1.5 block text-xs font-semibold text-fg-soft">Payment terms (days)</label>
    <input id="customer-payment-terms" type="number" min="0" max="365" step="1" wire:model="customerPaymentTerms" class="{{ $inputClasses }} sm:max-w-[160px]">
    @error('customerPaymentTerms') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
</div>
