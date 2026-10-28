@extends('layouts.app')

@section('content')
<div class="max-w-2xl bg-white p-8 rounded shadow">
    <h2 class="text-xl font-bold mb-4">Decline Internship Offer</h2> <p class="text-gray-600 mb-6">Select a template to send a professional rejection notice to the employer.</p>

    <form>
        <div class="mb-4">
            <label class="block font-bold mb-2">Rejection Template</label> <select class="w-full border p-2 rounded">
                <option>Template 1: Better Offer Elsewhere</option>
                <option>Template 2: Location Constraints</option>
                <option>Template 3: Change in Career Interest</option>
            </select>
        </div>

        <div class="mb-4">
            <label class="block font-bold mb-2">Message Preview</label>
            <textarea class="w-full border p-2 rounded h-32 bg-gray-50" readonly>
Dear Hiring Manager, 

Thank you for the offer. After careful consideration, I have decided to accept another offer that aligns more closely with my current goals. I appreciate your time.
            </textarea> </div>

        <button type="button" class="w-full bg-red-600 text-white font-bold py-2 rounded">Send Rejection Notice</button>
    </form>
</div>
@endsection