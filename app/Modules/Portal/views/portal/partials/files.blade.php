<div>
    <label for="files" class="block text-sm font-medium">{{ __('Bifoga filer (frivilligt)') }}</label>
    <input id="files" name="files[]" type="file" multiple accept="application/pdf,image/jpeg,image/png" class="mt-1 block w-full text-sm" aria-describedby="files-help @error('files') files-error @enderror">
    <p id="files-help" class="mt-1 text-xs text-gray-600">{{ __('PDF, JPEG eller PNG, högst 10 MB per fil och fem filer.') }}</p>
    @error('files')<p id="files-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
</div>
