<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Internship Post-Offer System</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <nav class="bg-blue-900 text-white p-4 shadow-lg">
        <div class="container mx-auto flex justify-between items-center">
            <h1 class="text-xl font-bold">UiTM Internship Portal</h1>
            <div class="space-x-4 text-sm">
                <span>Welcome, <strong>Alif Mukhlis</strong></span> <a href="#" class="hover:underline">Logout</a>
            </div>
        </div>
    </nav>

    <div class="flex">
        <aside class="w-64 bg-white h-screen shadow-md">
            <ul class="p-4 space-y-4">
                <li><a href="/dashboard" class="block p-2 bg-blue-100 text-blue-700 rounded">Dashboard</a></li>
                <li><a href="/offers" class="block p-2 hover:bg-gray-100">Internship Offers</a></li>
                <li><a href="/profile" class="block p-2 hover:bg-gray-100">Profile Management</a></li>
            </ul>
        </aside>

        <main class="flex-1 p-8">
            @yield('content')
        </main>
    </div>
</body>
</html>