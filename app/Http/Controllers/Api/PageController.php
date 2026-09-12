<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PageController extends Controller
{
    public function health()
    {
        return response()->json([
            'success' => true,
            'status' => 'ok',
            'timestamp' => now()->toISOString(),
            'app' => config('app.name'),
            'version' => '1.0.0',
        ]);
    }

    public function home()
    {
        $movies = $this->visibleMovies();

        return $this->successResponse([
            'featured' => $movies[0] ?? null,
            'movies' => $movies,
        ], 'Home page data retrieved successfully.');
    }

    public function movies()
    {
        return $this->successResponse($this->visibleMovies(), 'Movies retrieved successfully.');
    }

    public function movie(string $id)
    {
        $movie = collect($this->visibleMovies())->firstWhere('id', $id);

        if (! $movie) {
            return $this->errorResponse('Movie not found.', 404);
        }

        return $this->successResponse($movie, 'Movie details retrieved successfully.');
    }

    public function favorites(Request $request)
    {
        $email = strtolower(trim((string) $request->query('email', '')));

        return $this->successResponse($this->favoritesData($email), 'Favorites retrieved successfully.');
    }

    public function toggleFavorite(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'movie_id' => ['required', 'string', 'max:50'],
            'is_favorite' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $email = strtolower(trim((string) $request->input('email')));
        $movieId = (string) $request->input('movie_id');
        $isFavorite = (bool) $request->input('is_favorite');
        $movie = collect($this->moviesData())->firstWhere('id', $movieId);

        if (! $movie) {
            return $this->errorResponse('Movie not found.', 404);
        }

        $favorites = $this->favoritesFileData();
        $filtered = array_values(array_filter($favorites, function ($item) use ($email, $movieId) {
            return strtolower((string) ($item['user_email'] ?? '')) !== $email || (string) ($item['movie_id'] ?? '') !== $movieId;
        }));

        if (! $isFavorite) {
            $this->saveFavoritesFile($filtered);

            return $this->successResponse([
                'is_favorite' => false,
                'favorites' => $filtered,
            ], 'Movie removed from favorites.');
        }

        $existing = collect($favorites)->first(fn ($item) => strtolower((string) ($item['user_email'] ?? '')) === $email && (string) ($item['movie_id'] ?? '') === $movieId);

        if ($existing) {
            return $this->successResponse([
                'is_favorite' => true,
                'favorites' => $favorites,
            ], 'Movie already saved to favorites.');
        }

        $favorite = [
            'id' => $movieId,
            'title' => $movie['title'],
            'user_email' => $email,
            'movie_id' => $movieId,
            'movie_title' => $movie['title'],
            'genres' => $movie['genres'] ?? [],
            'date' => $movie['date'],
            'time' => $movie['time'],
            'availableSeats' => $movie['availableSeats'] ?? 0,
            'totalSeats' => $movie['totalSeats'] ?? 0,
            'location' => $movie['location'],
            'image' => $movie['image'],
            'created_at' => now()->toISOString(),
        ];

        $filtered[] = $favorite;
        $this->saveFavoritesFile($filtered);

        return $this->successResponse([
            'is_favorite' => true,
            'favorite' => $favorite,
            'favorites' => $filtered,
        ], 'Movie added to favorites.');
    }

    public function transactions(Request $request)
    {
        $email = strtolower(trim((string) $request->query('email', '')));
        $items = $this->transactionsData($email);
        $status = strtolower((string) $request->query('status', 'all'));
        $query = strtolower(trim((string) $request->query('search', '')));

        if ($status !== 'all') {
            $items = array_values(array_filter($items, fn ($item) => strtolower((string) ($item['status'] ?? '')) === $status));
        }

        if ($query !== '') {
            $items = array_values(array_filter($items, function ($item) use ($query) {
                $title = strtolower((string) ($item['movie_title'] ?? $item['title'] ?? ''));
                $ref = strtolower((string) ($item['ref_code'] ?? ''));

                return str_contains($title, $query) || str_contains($ref, $query);
            }));
        }

        return $this->successResponse($items, 'Transactions retrieved successfully.');
    }

    public function cancelReservation(Request $request, string $id)
    {
        $transactions = $this->transactionsFileData();
        $index = collect($transactions)->search(fn ($item) => (($item['id'] ?? '') === $id), true);

        if ($index === false) {
            return $this->errorResponse('Reservation not found.', 404);
        }

        $transaction = $transactions[$index];
        $transaction['status'] = 'Cancelled';
        $transaction['cancelled_at'] = now()->toISOString();
        $transaction['updated_at'] = now()->toISOString();
        $transaction['ref_code'] = $transaction['ref_code'] ?? null;
        $transactions[$index] = $transaction;
        $this->saveTransactionsFile($transactions);

        return $this->successResponse($transaction, 'Reservation cancelled successfully.');
    }

    public function createReservation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'movie_id' => ['required', 'string'],
            'movie_title' => ['required', 'string', 'max:150'],
            'location' => ['required', 'string', 'max:150'],
            'price' => ['required', 'numeric', 'min:0'],
            'ticket_count' => ['required', 'integer', 'min:1'],
            'customer_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:40'],
            'gender' => ['nullable', 'string', 'max:40'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $movie = collect($this->moviesData())->firstWhere('id', (string) $request->input('movie_id'));
        $amount = (float) $request->input('price') * (int) $request->input('ticket_count');
        $email = strtolower(trim((string) $request->input('email')));

        $transaction = [
            'id' => 'TX-' . strtoupper(bin2hex(random_bytes(6))),
            'user_email' => $email,
            'movie_id' => (string) $request->input('movie_id'),
            'movie_title' => $request->input('movie_title'),
            'image' => $movie['image'] ?? '',
            'date' => now()->format('M d, Y'),
            'time' => $movie['time'] ?? '',
            'location' => $request->input('location'),
            'genres' => $movie['genres'] ?? [],
            'ref_code' => null,
            'seats' => [],
            'payment_method' => null,
            'amount' => $amount,
            'status' => 'Pending',
            'customer_name' => $request->input('customer_name'),
            'phone' => $request->input('phone'),
            'gender' => $request->input('gender', 'Male'),
            'ticket_count' => (int) $request->input('ticket_count'),
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ];

        $transactions = $this->transactionsFileData();
        $transactions[] = $transaction;
        $this->saveTransactionsFile($transactions);

        return $this->successResponse($transaction, 'Reservation details created successfully.');
    }

    public function completeReservation(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'payment_method' => ['required', 'string', 'max:40'],
            'seats' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $transactions = $this->transactionsFileData();
        $index = collect($transactions)->search(fn ($item) => (($item['id'] ?? '') === $id), true);

        if ($index === false) {
            return $this->errorResponse('Reservation not found.', 404);
        }

        $transaction = $transactions[$index];
        $movieId = (string) ($transaction['movie_id'] ?? '');
        $requestedSeats = $request->input('seats');
        $seats = is_array($requestedSeats) && count($requestedSeats) > 0
            ? array_values(array_map('strval', $requestedSeats))
            : $this->generateUniqueSeatsForMovie($movieId, max(1, (int) ($transaction['ticket_count'] ?? 1)));

        if (count($seats) === 0) {
            return $this->errorResponse('No seats are available for this movie right now.', 422);
        }

        $refCode = 'CB-' . now()->format('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 4));

        $transaction['payment_method'] = $request->input('payment_method');
        $transaction['status'] = 'Completed';
        $transaction['ref_code'] = $refCode;
        $transaction['seats'] = array_values(array_unique($seats));
        $transaction['updated_at'] = now()->toISOString();
        $transaction['completed_at'] = now()->toISOString();
        $transactions[$index] = $transaction;
        $this->saveTransactionsFile($transactions);

        return $this->successResponse($transaction, 'Reservation completed successfully.');
    }

    public function notifications(Request $request)
    {
        $items = $this->notificationsData();
        $category = $request->query('category', 'All Notifications');

        if ($category !== 'All Notifications') {
            $items = array_values(array_filter($items, fn ($item) => $item['category_filter'] === $category));
        }

        return $this->successResponse($items, 'Notifications retrieved successfully.');
    }

    public function profile(Request $request)
    {
        $email = strtolower(trim((string) $request->query('email', '')));

        if ($email === '') {
            return $this->errorResponse('User email is required to load the profile.', 400);
        }

        return $this->successResponse($this->profileData($email), 'Profile retrieved successfully.');
    }

    public function updateProfile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'first_name' => ['nullable', 'string', 'max:80'],
            'middle_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'age' => ['nullable', 'integer', 'min:0'],
            'birthday' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:40'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'avatar_url' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $email = strtolower(trim((string) $request->input('email')));
        $users = $this->usersData();
        $index = collect($users)->search(fn ($user) => strtolower((string) ($user['email'] ?? '')) === $email, true);

        if ($index === false) {
            return $this->errorResponse('User not found.', 404);
        }

        $user = $users[$index];
        $phone = trim((string) ($request->input('phone') ?? $user['phone'] ?? ''));

        if ($phone !== '' && ! preg_match('/^09\d{9}$/', $phone)) {
            return $this->errorResponse('Phone number must start with 09 and contain exactly 11 digits.', 422);
        }

        $user['first_name'] = $request->input('first_name', $user['first_name'] ?? '');
        $user['middle_name'] = $request->input('middle_name', $user['middle_name'] ?? '');
        $user['last_name'] = $request->input('last_name', $user['last_name'] ?? '');
        $user['age'] = (int) ($request->input('age', $user['age'] ?? 0));
        $user['birthday'] = $request->input('birthday', $user['birthday'] ?? null);
        $user['gender'] = $request->input('gender', $user['gender'] ?? 'Male');
        $user['phone'] = $phone;
        $user['email'] = strtolower((string) $request->input('email', $user['email']));
        $user['address'] = $request->input('address', $user['address'] ?? '');
        $user['avatar_url'] = $request->input('avatar_url', $user['avatar_url'] ?? null);
        $users[$index] = $user;

        $this->saveUsersFile($users);

        return $this->successResponse([
            'user' => [
                'id' => $user['id'] ?? null,
                'email' => $user['email'],
                'first_name' => $user['first_name'],
                'middle_name' => $user['middle_name'],
                'last_name' => $user['last_name'],
                'name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
                'role' => $user['role'] ?? 'customer',
                'avatar_url' => $user['avatar_url'] ?? null,
            ],
        ], 'Profile updated successfully.');
    }

    public function settings()
    {
        return $this->successResponse([
            'security' => [
                'password_policy' => 'Minimum 8 characters with uppercase, lowercase, and number.',
                'two_factor_enabled' => true,
                'last_password_update' => '2026-09-01',
            ],
            'preferences' => [
                'email_notifications' => true,
                'sms_notifications' => false,
                'dark_mode' => true,
            ],
        ], 'Settings retrieved successfully.');
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $users = $this->usersData();
        $user = collect($users)->first(fn ($entry) => strtolower((string) $entry['email']) === strtolower((string) $request->input('email')));

        if (! $user || ! password_verify((string) $request->input('password'), (string) $user['password'])) {
            return $this->errorResponse('Invalid email or password.', 401);
        }

        $payload = [
            'id' => $user['id'],
            'email' => $user['email'],
            'first_name' => $user['first_name'] ?? '',
            'middle_name' => $user['middle_name'] ?? '',
            'last_name' => $user['last_name'] ?? '',
            'name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
            'role' => $user['role'] ?? 'customer',
            'avatar_url' => $user['avatar_url'] ?? 'https://kjugarap.top/cdn/projects-imgs/default.png',
        ];

        return $this->successResponse([
            'user' => $payload,
            'token' => 'cinebook_' . bin2hex(random_bytes(16)),
        ], 'Login successful.');
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'firstName' => ['required', 'string', 'max:80'],
            'lastName' => ['required', 'string', 'max:80'],
            'birthday' => ['required', 'date'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $phone = trim((string) ($request->input('phone') ?? ''));
        if ($phone !== '' && ! preg_match('/^09\d{9}$/', $phone)) {
            return $this->errorResponse('Phone number must start with 09 and contain exactly 11 digits.', 422);
        }

        $existingUser = collect($this->usersData())->first(fn ($entry) => strtolower((string) $entry['email']) === strtolower((string) $request->input('email')));

        if ($existingUser) {
            return $this->errorResponse('This email is already registered.', 422);
        }

        $firstName = trim((string) $request->input('firstName'));
        $lastName = trim((string) $request->input('lastName'));
        $defaultAvatar = 'https://kjugarap.top/cdn/projects-imgs/default.png';

        $birthday = $request->input('birthday');
        $age = 0;
        if (is_string($birthday) && $birthday !== '') {
            $birthDate = new \DateTime($birthday);
            $today = new \DateTime('today');
            $age = (int) $today->diff($birthDate)->y;
        }

        $user = [
            'id' => time(),
            'first_name' => $firstName,
            'middle_name' => '',
            'last_name' => $lastName,
            'age' => $age,
            'birthday' => $birthday,
            'gender' => $request->input('gender') ?? 'Male',
            'phone' => $request->input('phone') ?? '',
            'email' => strtolower((string) $request->input('email')),
            'password' => password_hash((string) $request->input('password'), PASSWORD_DEFAULT),
            'address' => $request->input('address') ?? '',
            'avatar_url' => $request->input('avatarUrl') ?? $defaultAvatar,
            'role' => 'customer',
        ];

        $users = $this->usersData();
        $users[] = $user;
        $this->saveUsersFile($users);

        return $this->successResponse([
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'first_name' => $user['first_name'],
                'middle_name' => $user['middle_name'],
                'last_name' => $user['last_name'],
                'name' => trim($user['first_name'] . ' ' . $user['last_name']),
                'role' => $user['role'],
                'avatar_url' => $user['avatar_url'],
            ],
            'token' => 'cinebook_' . bin2hex(random_bytes(16)),
        ], 'Registration successful.');
    }

    public function uploadMovieImage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $file = $request->file('image');
        if (! $file || ! $file->isValid()) {
            return $this->errorResponse('Uploaded movie image is invalid.', 422);
        }

        $directory = public_path('uploads/movies');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $filename = 'movie-' . time() . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $file->move($directory, $filename);

        return $this->successResponse([
            'image_url' => url('uploads/movies/' . $filename),
            'filename' => $filename,
        ], 'Movie image uploaded successfully.');
    }

    public function adminStore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:150'],
            'genre' => ['required', 'string', 'max:120'],
            'duration' => ['required', 'string', 'max:50'],
            'availableSeats' => ['required', 'integer', 'min:0'],
            'showtime' => ['required', 'date'],
            'location' => ['required', 'string', 'max:150'],
            'synopsis' => ['required', 'string', 'min:20'],
            'ticketPrice' => ['required', 'numeric', 'min:0'],
            'posterUrl' => ['nullable', 'string', 'max:500'],
            'image_url' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $movieId = 'movie-' . time() . '-' . random_int(1000, 9999);
        $title = trim((string) $request->input('title'));
        $genre = trim((string) $request->input('genre'));
        $posterUrl = trim((string) ($request->input('posterUrl') ?: $request->input('image_url')));
        $showtime = $request->input('showtime');
        $showtimeDate = $showtime ? date('M d, Y', strtotime($showtime)) : now()->format('M d, Y');
        $showtimeTime = $showtime ? date('g:i A', strtotime($showtime)) : '7:30 PM';

        $movie = [
            'id' => $movieId,
            'title' => $title,
            'genres' => array_values(array_filter(array_map('trim', preg_split('/[\/,&]+/', $genre)), fn ($value) => $value !== '')),
            'date' => $showtimeDate,
            'time' => $showtimeTime,
            'availableSeats' => (int) $request->input('availableSeats'),
            'totalSeats' => max((int) $request->input('availableSeats'), 1),
            'location' => $request->input('location'),
            'status' => 'Available',
            'image' => $posterUrl !== '' ? $posterUrl : 'https://images.unsplash.com/photo-1536440136628-849c177e76a1?w=500&auto=format&fit=crop',
            'rating' => '4.8',
            'duration' => $request->input('duration'),
            'showtime' => $showtimeTime . ' • ' . $showtimeDate,
            'price' => (float) $request->input('ticketPrice'),
            'description' => $request->input('synopsis'),
        ];

        $movies = $this->moviesFileData();
        $movies[] = $movie;
        $this->saveMoviesFile($movies);

        return $this->successResponse([
            'movie' => [
                'id' => $movie['id'],
                'title' => $movie['title'],
                'genres' => $movie['genres'],
                'duration' => $movie['duration'],
                'availableSeats' => $movie['availableSeats'],
                'showtime' => $movie['showtime'],
                'location' => $movie['location'],
                'description' => $movie['description'],
                'price' => $movie['price'],
                'image' => $movie['image'],
            ],
        ], 'Movie created successfully.');
    }

    private function successResponse(mixed $data, string $message = 'Success', int $status = 200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    private function errorResponse(string $message, int $status = 400)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }

    private function moviesData(): array
    {
        $fileMovies = $this->moviesFileData();

        if (! empty($fileMovies)) {
            return $fileMovies;
        }

        return $this->defaultMoviesData();
    }

    private function defaultMoviesData(): array
    {
        return [
            [
                'id' => '1',
                'title' => 'Demon Slayer: Infinity Castle',
                'genres' => ['Action', 'Adventure', 'Fantasy'],
                'date' => 'Aug 30, 2025',
                'time' => '1:00 PM',
                'availableSeats' => 120,
                'totalSeats' => 150,
                'location' => 'SM City Cebu',
                'status' => 'Available',
                'image' => 'https://images.unsplash.com/photo-1536440136628-849c177e76a1?w=500&auto=format&fit=crop',
                'rating' => '4.8',
                'duration' => '2h 28m',
                'showtime' => '7:30 PM • Sept 15, 2026',
                'price' => 350,
                'description' => 'A thief who steals corporate secrets through the use of dream-sharing technology is given the inverse task of planting an idea into the mind of a C.E.O., but his tragic past may doom the project and his team to disaster.',
            ],
            [
                'id' => '2',
                'title' => 'How to Train Your Dragon',
                'genres' => ['Animation', 'Adventure', 'Family'],
                'date' => 'Aug 30, 2025',
                'time' => '3:30 PM',
                'availableSeats' => 98,
                'totalSeats' => 120,
                'location' => 'Gaisano Grand',
                'status' => 'Available',
                'image' => 'https://images.unsplash.com/photo-1518709268805-4e9042af9f23?w=500&auto=format&fit=crop',
                'rating' => '4.7',
                'duration' => '1h 58m',
                'showtime' => '3:30 PM • Sept 16, 2026',
                'price' => 280,
                'description' => 'A young Viking and dragon rider discover a new understanding of bonding, courage, and family.',
            ],
            [
                'id' => '3',
                'title' => 'John Wick 4',
                'genres' => ['Action', 'Thriller', 'Crime'],
                'date' => 'Aug 30, 2025',
                'time' => '6:00 PM',
                'availableSeats' => 45,
                'totalSeats' => 100,
                'location' => 'SM City Cebu',
                'status' => 'Available',
                'image' => 'https://images.unsplash.com/photo-1489599849927-2ee91cede3ba?w=500&auto=format&fit=crop',
                'rating' => '4.9',
                'duration' => '2h 49m',
                'showtime' => '6:00 PM • Sept 15, 2026',
                'price' => 410,
                'description' => 'John Wick faces off against new enemies in a relentless, action-packed continuation of the saga.',
            ],
            [
                'id' => '4',
                'title' => 'Inside Out 2',
                'genres' => ['Animation', 'Comedy', 'Family'],
                'date' => 'Aug 30, 2025',
                'time' => '8:30 PM',
                'availableSeats' => 200,
                'totalSeats' => 200,
                'location' => 'Robinsons Galleria',
                'status' => 'Available',
                'image' => 'https://images.unsplash.com/photo-1535016120720-40c646be5580?w=500&auto=format&fit=crop',
                'rating' => '4.6',
                'duration' => '1h 36m',
                'showtime' => '8:30 PM • Sept 17, 2026',
                'price' => 260,
                'description' => 'Riley navigates new emotions while a colorful cast helps her adapt to the bigger changes of adolescence.',
            ],
        ];
    }

    private function moviesFileData(): array
    {
        $path = storage_path('app/data/movies.json');
        $items = $this->readJsonFile($path);

        return array_values($items);
    }

    private function saveMoviesFile(array $items): void
    {
        $path = storage_path('app/data/movies.json');
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function favoritesData(string $email = ''): array
    {
        $items = array_map(function ($item) {
            $movieId = (string) ($item['movie_id'] ?? $item['id'] ?? '');
            $title = (string) ($item['title'] ?? $item['movie_title'] ?? '');

            if ($movieId !== '' && ! isset($item['id'])) {
                $item['id'] = $movieId;
            }

            if ($movieId !== '' && ! isset($item['movie_id'])) {
                $item['movie_id'] = $movieId;
            }

            if ($movieId !== '' && ($item['id'] ?? '') === '') {
                $item['id'] = $movieId;
            }

            if ($title !== '' && ! isset($item['title'])) {
                $item['title'] = $title;
            }

            if ($title !== '' && ! isset($item['movie_title'])) {
                $item['movie_title'] = $title;
            }

            if (($item['title'] ?? '') === '' && $title !== '') {
                $item['title'] = $title;
            }

            if (($item['movie_title'] ?? '') === '' && $title !== '') {
                $item['movie_title'] = $title;
            }

            return $item;
        }, $this->favoritesFileData());

        if ($email === '') {
            return $items;
        }

        return array_values(array_filter($items, fn ($item) => strtolower((string) ($item['user_email'] ?? '')) === $email));
    }

    private function favoritesFileData(): array
    {
        $path = storage_path('app/data/favorites.json');
        $items = $this->readJsonFile($path);

        return array_values($items);
    }

    private function saveFavoritesFile(array $items): void
    {
        $path = storage_path('app/data/favorites.json');
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function transactionsData(string $email = ''): array
    {
        $items = $this->transactionsFileData();

        if ($email === '') {
            return $items;
        }

        return array_values(array_filter($items, fn ($item) => strtolower((string) ($item['user_email'] ?? '')) === $email));
    }

    private function transactionsFileData(): array
    {
        $path = storage_path('app/data/transactions.json');
        $items = $this->readJsonFile($path);

        return array_values($items);
    }

    private function saveTransactionsFile(array $items): void
    {
        $path = storage_path('app/data/transactions.json');
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function notificationsData(): array
    {
        return [
            [
                'id' => '1',
                'category' => 'New Movie',
                'category_filter' => 'New Movies',
                'title' => 'Kung Fu Panda 4 is now available!',
                'message' => 'The wait is over! Kung Fu Panda 4 is now showing at SM City Cebu. Book your seats now!',
                'timestamp' => 'Aug 30, 2025 • 10:24 AM',
                'image' => 'https://images.unsplash.com/photo-1517604931442-7e0c8ed2963c?w=500&auto=format&fit=crop',
                'is_read' => false,
            ],
            [
                'id' => '2',
                'category' => 'Booking Update',
                'category_filter' => 'Booking Updates',
                'title' => 'Your seat reservation is confirmed!',
                'message' => 'Hi! Your booking for The Batman has been confirmed. Check your transaction for your ticket details.',
                'timestamp' => 'Aug 29, 2025 • 06:15 PM',
                'image' => 'https://images.unsplash.com/photo-1509198397868-475647b2a1e5?w=500&auto=format&fit=crop',
                'is_read' => false,
            ],
            [
                'id' => '3',
                'category' => 'System Alert',
                'category_filter' => 'System Alerts',
                'title' => 'Scheduled Maintenance',
                'message' => 'The system will be under maintenance on Aug 31, 2025 from 1:00 AM to 4:00 AM. Please plan your bookings accordingly.',
                'timestamp' => 'Aug 26, 2025 • 09:10 AM',
                'image' => null,
                'is_read' => false,
            ],
        ];
    }

    private function profileData(string $email = ''): array
    {
        $users = $this->usersData();
        $email = strtolower(trim($email));

        $user = $email !== ''
            ? collect($users)->first(fn ($entry) => strtolower((string) ($entry['email'] ?? '')) === $email)
            : ($users[0] ?? null);

        if (! $user) {
            $user = [
                'first_name' => 'Cinebook',
                'middle_name' => '',
                'last_name' => 'User',
                'age' => 0,
                'birthday' => null,
                'gender' => 'Male',
                'phone' => '',
                'email' => 'user@cinebook.test',
                'address' => '',
                'avatar_url' => null,
            ];
        }

        return [
            'id' => $user['id'] ?? 1,
            'first_name' => $user['first_name'] ?? 'Cinebook',
            'middle_name' => $user['middle_name'] ?? '',
            'last_name' => $user['last_name'] ?? 'User',
            'age' => $user['age'] ?? 0,
            'birthday' => $user['birthday'] ?? null,
            'gender' => $user['gender'] ?? 'Male',
            'phone' => $user['phone'] ?? '',
            'email' => $user['email'] ?? 'user@cinebook.test',
            'address' => $user['address'] ?? '',
            'avatar_url' => $user['avatar_url'] ?? 'https://kjugarap.top/cdn/projects-imgs/default.png',
        ];
    }

    private function usersData(): array
    {
        $path = storage_path('app/data/users.json');

        if (! file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $users = json_decode($content, true);

        return is_array($users) ? $users : [];
    }

    private function saveUsersFile(array $items): void
    {
        $path = storage_path('app/data/users.json');
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function visibleMovies(): array
    {
        $movies = array_map(function ($movie) {
            $movie['status'] = $this->movieStatus($movie);
            $movie['availableSeats'] = $this->movieAvailableSeats($movie['id'] ?? '', (int) ($movie['availableSeats'] ?? 0), (int) ($movie['totalSeats'] ?? 0));
            return $movie;
        }, $this->moviesData());

        return array_values(array_filter($movies, function ($movie) {
            return $this->movieIsVisible($movie);
        }));
    }

    private function movieIsVisible(array $movie): bool
    {
        if (($movie['status'] ?? 'Available') === 'Sold Out') {
            return false;
        }

        $showtime = $this->movieShowtime($movie);
        if ($showtime === null) {
            return true;
        }

        $cutoff = $showtime->modify('-10 minutes');

        return now()->lt($cutoff);
    }

    private function movieStatus(array $movie): string
    {
        $availableSeats = $this->movieAvailableSeats((string) ($movie['id'] ?? ''), (int) ($movie['availableSeats'] ?? 0), (int) ($movie['totalSeats'] ?? 0));

        if ($availableSeats <= 0) {
            return 'Sold Out';
        }

        return 'Available';
    }

    private function movieAvailableSeats(string $movieId, int $defaultSeats, int $totalSeats): int
    {
        $transactions = $this->transactionsFileData();
        $reservedSeats = 0;

        foreach ($transactions as $transaction) {
            if ((string) ($transaction['movie_id'] ?? '') !== $movieId) {
                continue;
            }

            if (in_array((string) ($transaction['status'] ?? ''), ['Completed', 'Pending'], true)) {
                $reservedSeats += max(1, count($transaction['seats'] ?? []));
            }
        }

        $bookableSeats = $totalSeats > 0 ? $totalSeats : max($defaultSeats, 1);
        $remaining = $bookableSeats - $reservedSeats;

        return max(0, $remaining);
    }

    private function generateUniqueSeatsForMovie(string $movieId, int $count): array
    {
        if ($movieId === '' || $count <= 0) {
            return [];
        }

        $movie = collect($this->moviesData())->firstWhere('id', $movieId) ?? [];
        $totalSeats = max((int) ($movie['totalSeats'] ?? $movie['availableSeats'] ?? 150), $count);
        $allSeats = [];

        for ($row = 0; $row < 8; $row++) {
            $letter = chr(65 + $row);
            for ($seat = 1; $seat <= 25; $seat++) {
                $allSeats[] = $letter . $seat;
            }
        }

        $usedSeats = [];
        foreach ($this->transactionsFileData() as $transaction) {
            if ((string) ($transaction['movie_id'] ?? '') !== $movieId) {
                continue;
            }

            if (isset($transaction['seats']) && is_array($transaction['seats'])) {
                $usedSeats = array_merge($usedSeats, array_map('strval', $transaction['seats']));
            }
        }

        $availableSeats = array_values(array_diff($allSeats, $usedSeats));
        $selected = array_slice($availableSeats, 0, min($count, count($availableSeats)));

        if (count($selected) < $count) {
            return [];
        }

        return $selected;
    }

    private function movieShowtime(array $movie): ?\DateTimeImmutable
    {
        $raw = (string) ($movie['showtime'] ?? '');
        if ($raw !== '') {
            $normalized = str_replace('•', '', $raw);
            $time = strtotime(trim($normalized));
            if ($time !== false) {
                return new \DateTimeImmutable('@' . $time);
            }
        }

        $date = (string) ($movie['date'] ?? '');
        $time = (string) ($movie['time'] ?? '');
        $combined = trim($date . ' ' . $time);
        if ($combined !== '') {
            $timestamp = strtotime($combined);
            if ($timestamp !== false) {
                return new \DateTimeImmutable('@' . $timestamp);
            }
        }

        return null;
    }

    private function readJsonFile(string $path): array
    {
        if (! file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }
}
