@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-blue-900">Placement Monitoring & Analytics</h2> <button class="bg-gray-800 text-white px-4 py-2 rounded">Export CSV Report</button> </div>

    <div class="bg-white rounded shadow overflow-hidden">
        <table class="w-full text-left">
            <tr class="bg-blue-900 text-white">
                <th class="p-4">Student Name</th>
                <th class="p-4">Matric ID</th>
                <th class="p-4">Total Offers</th>
                <th class="p-4">Warning Status</th>
                <th class="p-4">Verification</th>
            </tr>
            <tr class="border-b">
                <td class="p-4">Alif Mukhlis</td> <td class="p-4">2025161705</td> <td class="p-4 text-red-600 font-bold">3 Offers (Hoarding Risk)</td> <td class="p-4"><span class="px-2 py-1 bg-red-100 text-red-800 rounded">High Risk</span></td>
                <td class="p-4"><button class="text-blue-600 underline">Verify Docs</button></td> </tr>
        </table>
    </div>
</div>
@endsection