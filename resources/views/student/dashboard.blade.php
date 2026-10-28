@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto">
    <h2 class="text-2xl font-bold mb-6">Student Internship Dashboard</h2> <div class="grid grid-cols-3 gap-4 mb-8">
        <div class="bg-white p-6 rounded shadow border-l-4 border-yellow-500">
            <p class="text-gray-500">Pending Offers</p>
            <h3 class="text-3xl font-bold">2</h3>
        </div>
        <div class="bg-white p-6 rounded shadow border-l-4 border-green-500">
            <p class="text-gray-500">Accepted</p>
            <h3 class="text-3xl font-bold">0</h3>
        </div>
        <div class="bg-white p-6 rounded shadow border-l-4 border-blue-500">
            <p class="text-gray-500">Applications Sent</p>
            <h3 class="text-3xl font-bold">5</h3>
        </div>
    </div>

    <div class="bg-white rounded shadow">
        <div class="p-4 border-b font-bold bg-gray-50">Current Internship Offers</div>
        <table class="w-full text-left">
            <thead>
                <tr class="bg-gray-100 text-sm">
                    <th class="p-4">Company Name</th>
                    <th class="p-4">Position</th>
                    <th class="p-4">Deadline</th>
                    <th class="p-4">Status</th>
                    <th class="p-4">Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr class="border-b">
                    <td class="p-4">Tech Solutions Sdn Bhd</td>
                    <td class="p-4">Back-end Developer</td>
                    <td class="p-4">20-Feb-2023</td>
                    <td class="p-4"><span class="px-2 py-1 bg-yellow-100 text-yellow-800 rounded">Pending</span></td>
                    <td class="p-4 space-x-2">
                        <button class="bg-green-600 text-white px-3 py-1 rounded text-sm">Accept</button>
                        <button class="bg-red-600 text-white px-3 py-1 rounded text-sm">Reject</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
@endsection