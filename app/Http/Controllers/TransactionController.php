<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Http;
use DateTime; // Import DateTime from the global namespace
use Exception; // Import Exception from the global namespace
use Silber\Bouncer\BouncerFacade;


class TransactionController extends Controller
{
    public function index(): Response
    {
        if(Auth::user()->cannot('manage-transactions'))
        {
            abort(403, 'Unauthorized access.');
        }
        else{
            return Inertia::render('Transactions/Index',[
                'transactions' => Transaction::with('user')
                ->where('user_id', Auth::id())
                ->latest()
                ->paginate(10),
                'isAdmin' => BouncerFacade::is(Auth::user())->an('admin'),
            ]);
        }
    }

    public function confirm(Request $request)
    {
        // Validate the confirmed data
        $request->validate([
            'reference_id' => 'required|string|unique:transactions,reference_id',
            'date' => 'required|date|after_or_equal:2024-11-10|before_or_equal:today',
            // 'date' => 'required|date',
            'amount' => 'required|numeric|min:0.01|max:999999.99',
            'image_url' => 'required|url',
        ]);

        logger()->info('Image URL in confirm:', ['url' => $request->image_url]);

        // Create and save a new transaction using the confirmed data
        $transaction = new Transaction();
        $transaction->user_id = Auth::id(); // Authenticated user
        $transaction->reference_id = $request->reference_id;
        $transaction->date = $request->date;
        $transaction->amount = $request->amount;
        $transaction->transaction_type = $request->transaction_type;
        $transaction->image_url = $request->image_url;

        // Save the transaction to the database
        $transaction->save();

        // Update the user's transaction counts
        try {
            $this->updateUserCounts($transaction); // Call the function here
        } catch (ValidationException $e) {
            // If there's an issue with updating counts, delete the transaction and re-throw the exception
            $transaction->delete();
            throw $e;
        }

        // Redirect the user back to the transactions index or another page
        return redirect()->route('transactions.index')->with('success', 'Transaction confirmed and saved successfully!');
    }

    public function store(Request $request)
	{
		// Validate the uploaded image
		$request->validate([
			'image_url' => 'required|file|mimes:jpeg,png,jpg|max:2048', // Ensure it's an image
		]);

		// Handle the image file if uploaded
		if ($request->hasFile('image_url')) {
			$imagePath = $request->file('image_url')->store('transactions', 'public'); // Store the image

            $imageUrl = asset('storage/' . $imagePath);
			// Full path to the uploaded image
			$imageFullPath = storage_path('app/public/' . $imagePath);

			// OCR.space API key (replace 'your_api_key' with your actual API key)
			$apiKey = 'K87900716488957';

			// Make a request to OCR.space API
			$response = Http::attach(
				'file', file_get_contents($imageFullPath), 'image.jpg'
			)->post('https://api.ocr.space/parse/image', [
				'apikey' => $apiKey,
				'language' => 'eng',
				'isOverlayRequired' => 'false', // Set to 'false' as a string
			]);

			// Decode JSON response from OCR.space
			$ocrData = $response->json();

			if (isset($ocrData['ParsedResults'][0]['ParsedText'])) {
				// Extracted text from OCR.space
				$extractedText = $ocrData['ParsedResults'][0]['ParsedText'];

				// Use helper methods to extract specific data from the text
				$reference_id = $this->extractReferenceID($extractedText);
				$date = $this->extractDate($extractedText);
				$amount = $this->extractAmount($extractedText);
                $transaction_type = $this->extractTransactionType($extractedText);

				// Log the full extracted text for debugging purposes
				logger()->info('Extracted Text:', ['text' => $extractedText]);
				logger()->info('Extracted Data:', [
					'reference_id' => $reference_id,
					'date' => $date,
					'amount' => $amount,
                    'transaction_type' => $transaction_type,
                    'image_url' => $imageUrl,
				]);

				return redirect()->route('transactions.show')
								->with([
									'reference_id' => $reference_id,
									'date' => $date,
									'amount' => $amount,
                                    'transaction_type' => $transaction_type,
                                    'image_url' => $imageUrl,
								]);
			} else {
				logger()->error('OCR.space API Error:', ['response' => $ocrData]);
				return back()->withErrors(['image_url' => 'OCR failed to extract text. Please try again.']);
			}
		}

		return back()->withErrors(['image_url' => 'Image upload failed']);
	}

    private function extractReferenceID($text) {
        // Preprocess the text
        $text = str_replace(["\n", "\r"], ' ', $text); // Remove line breaks
        $text = preg_replace('/\s+/', ' ', $text); // Replace multiple spaces with a single space
        logger()->info('Preprocessed OCR Text:', ['text' => $text]);

        // Adjust regex for OCR misinterpretations (allow `O` for `0`, etc.)
        $correctedText = str_replace(['I', 'O', 'S'], ['1', '0', '5'], $text);
        logger()->info('Corrected OCR Text:', ['text' => $correctedText]);

        if (strpos($correctedText, 'BANK@AM') !== false) {
            logger()->info('Match:', ['text' => 'I found it!']);
            if (preg_match('/Reference No.\s*[\r\n]?\s*(\w+)/i', $correctedText, $matches)) {
                return $matches[1];
            }
        }
        else if (strpos($correctedText, '0CT0') !== false) {
            logger()->info('Match:', ['text' => 'I found it!']);

            // Updated regex to capture both the 9-digit and 8-digit numbers
            if (preg_match('/DuitNow Reference No.*?(\d{9})\s(\d{8})/i', $correctedText, $matches)) {
                logger()->info('Match:', ['text' => 'I found DuitNow Reference No.!']);
                logger()->info('Full Match:', ['nine_digit' => $matches[1], 'eight_digit' => $matches[2]]);

                // Return only the 8-digit part
                return $matches[2];
            }
        }
        else if (strpos($text, 'Maybank') !== false) {
            logger()->info('Match:', ['text' => 'I found MAYBANK!']);
            // Adjusted regex to allow flexible spacing and ensure it captures the correct ID
            if (preg_match('/Reference I D.*?(\d{8})/i', $text, $matches)) {
                logger()->info('Match:', ['text' => 'I found Reference I D!']);
                return $matches[1];
            }
            else if (preg_match('/Reference ID.*?(\d{8})/i', $text, $matches)) {
                logger()->info('Match:', ['text' => 'I found Reference ID!']);
                return $matches[1];
            }
        }
        else if (strpos($text, 'RHB') !== false) {
            logger()->info('Match:', ['text' => 'I found RHB!']);

            if (preg_match('/(\d{8}RHBBMYKL[\w\d]+QR\s*\d{3}\s*\d{5})/i', $text, $matches)) {
                logger()->info('Match:', ['text' => 'I found RHBBMYKL with specific 8-digit handling!']);

                // Remove spaces from the captured string
                $referenceID = str_replace(' ', '', $matches[1]);

                return $referenceID;
            }

            if (preg_match('/(\d{8}RHBBMYKL[\w\d]+QR[\w\d]+)/i', $text, $matches)) {
                return $matches[1];
            }
        }
        else if (strpos($correctedText, 'Wallet') !== false) {
            logger()->info('Match:', ['text' => 'I found TNG!']);
            if (preg_match('/(\d{8}TNGDMYNB\d{4}QR)\s*Transaction No\.\s*([\w\d]+)/i', $correctedText, $matches)) {
                return $matches[1] . $matches[2];
            }
            else if (preg_match('/Transaction No\.\s*(.+)/i', $correctedText, $matches)) {
                $allTextAfterTransactionNo = $matches[1]; // Capture all text after "Transaction No."

                // Split the captured text into words and return the last valid alphanumeric word
                $segments = preg_split('/\s+/', trim($allTextAfterTransactionNo)); // Split by spaces
                $lastSegment = end($segments); // Get the last segment

                logger()->info('Extracted Segments:', ['segments' => $segments]);
                logger()->info('Extracted Reference ID:', ['reference_id' => $lastSegment]);

                return $lastSegment; // Return only the last valid segment
            }
        }
        else if (strpos($text, 'HLB') !== false) {
            logger()->info('Match:', ['text' => 'I found HLB!']);
            // Adjusted regex to handle spaces and extract all parts correctly
            if (preg_match('/(\d{8}HLBBMYKLO)\s*(\d{3,4})QR(\d{8})/i', $text, $matches)) {
                logger()->info('Match:', ['text' => 'I found HLBBMYKLO!']);
                logger()->info('Full Match:', ['first_part' => $matches[1], 'second_part' => $matches[2], 'third_part' => $matches[3]]);

                // Combine the parts and remove any spaces
                $referenceID = str_replace(' ', '', $matches[1] . $matches[2] . 'QR' . $matches[3]);

                return $referenceID;
            }
            else if (preg_match('/(\d{8}HLBBMYKL0)\s*(\d{3,4})QR(\d{8})/i', $text, $matches)) {
                logger()->info('Match:', ['text' => 'I found HLBBMYKLO!']);
                logger()->info('Full Match:', ['first_part' => $matches[1], 'second_part' => $matches[2], 'third_part' => $matches[3]]);

                // Combine the parts and remove any spaces
                $referenceID = str_replace(' ', '', $matches[1] . $matches[2] . 'QR' . $matches[3]);

                return $referenceID;
            }
            else if (preg_match('/(\d{8}HLBBMYKLO)\s*(\d{3,4})R\s*M(\d{8})/i', $text, $matches)) {
                logger()->info('Match:', ['text' => 'I found HLBBMYKLO!']);
                logger()->info('Full Match:', ['first_part' => $matches[1], 'second_part' => $matches[2], 'third_part' => $matches[3]]);

                // Combine the parts and remove any spaces
                $referenceID = str_replace(' ', '', $matches[1] . $matches[2] . 'QR' . $matches[3]);

                return $referenceID;
            }
            else if (preg_match('/(\d{8}HLBBMYKL0)\s*(\d{3,4})R\s*M(\d{8})/i', $text, $matches)) {
                logger()->info('Match:', ['text' => 'I found HLBBMYKLO!']);
                logger()->info('Full Match:', ['first_part' => $matches[1], 'second_part' => $matches[2], 'third_part' => $matches[3]]);

                // Combine the parts and remove any spaces
                $referenceID = str_replace(' ', '', $matches[1] . $matches[2] . 'QR' . $matches[3]);

                return $referenceID;
            }
        }
        else if (strpos($text, 'PUBLIC BANK') !== false) {
            logger()->info('Match:', ['text' => 'I found PUBLIC!']);
            if (preg_match('/DuitNow QR Ref No.*?(\d{8})/i', $text, $matches)) {
                logger()->info('Match:', ['text' => 'I found DuitNow QR Ref No.!']);
                return $matches[1];
            }
        }
        else if (strpos($text, 'alliance') !== false) {
            logger()->info('Match:', ['text' => 'I found alliance!']);

            // Regex to capture the reference number regardless of spacing or separators
            if (preg_match('/DuitNow QR Reference.*?Number.*?(\d{8})/is', $text, $matches)) {
                logger()->info('Match:', ['text' => 'I found DuitNow QR Reference Number!']);
                return $matches[1];
            }
        }
        else if (strpos($text, 'Al-Awfar') !== false) {
            logger()->info('Match:', ['text' => 'I found Al-Awfar!']);

            // Regex to capture the reference number regardless of spacing or separators
            if (preg_match('/DuitNow\s*QR\s*Ref\s*No\s*[:\-]?\s*(\d{8})/i', $text, $matches)) {
                logger()->info('Match:', ['text' => 'I found DuitNow QR Ref No:!']);
                return $matches[1];
            }
        }
        else{
            $patterns = [
                '/Reference ID\s*[\r\n]?\s*(\w+)/i',          // Matches 'Reference ID'
                '/Transaction No.\s*[\r\n]?\s*(\w+)/i',       // Matches 'Transaction No.'
                '/Reference No.\s*[\r\n]?\s*(\w+)/i',         // Matches 'Reference No.'
                '/Reference Number\s*[\r\n]?\s*(\w+)/i',         // Matches 'Reference Number'
            ];

            logger()->info('Match:', ['text' => 'I found from else!']);

            // Iterate through each pattern to find a match
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text, $matches)) {
                    return $matches[1]; // Return the matched reference number
                }
            }
        }
        return null; // Fallback if no match is found
    }

    private function extractDate($text) {
        $correctedText = str_replace(['I', 'S'], ['1', '5'], $text);
        // Define multiple regex patterns to handle different date formats
        $patterns = [
            // Pattern for formats like '15 Oct 2024 05:03 pm' or '28 Sep 2024, 4:13 PM'
           '/(\d{1,2}\s*\w{3,}\s*\d{4})\s*,?\s*\d{1,2}:\d{2}\s*(AM|PM)?/i',

            // Pattern for formats like '15 Oct 2024'
            '/(\d{1,2}\s*\w{3,}\s*\d{4})/i',

            // Pattern for formats like '16/10/2024' or '16-10-2024'
            '/(\d{1,2}[-\/]\d{1,2}[-\/]\d{4})/i',

             // Pattern for formats like '22-Nov-2024'
            '/(\d{1,2}-\w{3,}-\d{4})/i',

            // New pattern for specific CIMB format (e.g., '15 Oct 2024 05:05:14 PM')
            '/(\d{1,2}\s*\w{3,}\s*\d{4})\s*(\d{1,2}:\d{2}:\d{2}\s*(AM|PM)?)/i',
        ];

        // Iterate over the patterns and attempt to match the text
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $correctedText, $matches)) {
                $dateString = $matches[1]; // Get the matched date

                // Try different formats to convert to 'Y-m-d' format (e.g., 2024-10-06)
                $formats = ['d M Y', 'd/m/Y', 'd-m-Y', 'd-M-Y'];
                foreach ($formats as $format) {
                    try {
                        $date = DateTime::createFromFormat($format, $dateString);
                        if ($date) {
                            return $date->format('Y-m-d');
                        }
                    } catch (Exception $e) {
                        // Log error if date parsing fails
                        logger()->error('Date format error: ' . $e->getMessage());
                    }
                }
            }
        }

        // If no pattern matches or parsing fails, return null
        return null;
    }

    private function extractAmount($text) {
        $text = str_replace(["\n", "\r"], ' ', $text); // Remove line breaks
        $text = preg_replace('/\s+/', ' ', $text); // Replace multiple spaces with a single space
		// Correct common OCR misinterpretations for numbers
		$text = str_replace(['I', 'O'], ['1', '0'], $text);

        // Normalise spacing issues for (MYR)
        $text = preg_replace('/\(\s*MY\s*R\s*\)/iu', '(MYR)', $text);

        logger()->info('Normalized Text for Amount Extraction:', ['text' => $text]);

		// Adjust regex to allow optional space or hyphen between "RM" and the amount
		if (preg_match('/(?<!\S)-?\s*RM\s*([0-9]+(?:\.[0-9]{2})?)/iu', $text, $matches)) {
			return $matches[1]; // Return the amount after "RM"
		}

		// Adjust regex for "MYR" amounts (specific for the RHB case)
		if (preg_match('/MYR\s*([0-9]+(?:\.[0-9]{2})?)/iu', $text, $matches)) {
			return $matches[1]; // Return the amount after "MYR"
		}

        // New pattern to match "1.00 MYR" or similar formats
        if (preg_match('/([0-9]+(?:\.[0-9]{2})?)\s*MYR/iu', $text, $matches)) {
            return $matches[1]; // Return the amount before "MYR"
        }

        if (preg_match('/\(MYR\)\s*([0-9]+(?:\.[0-9]{2})?)/iu', $text, $matches)) {
            return $matches[1]; // Format: (MYR) 7.00
        }

        if (preg_match('/\(MYR\).*?([0-9]+(?:\.[0-9]{2})?)/iu', $text, $matches)) {
            logger()->info('Match:', ['text' => 'I found MYR!']);
            return $matches[1]; // Format: (MYR) 7.00
        }

		return null; // If neither pattern matches
	}

    private function extractTransactionType($text) {
        // Define known transaction type keywords, from more specific to more generic
        $transactionTypes = [
            'DuitNow QR TNGD', // more specific
            'DuitNow QR TNGo', // similar variations, prioritize if any
            'DuitNow QR',      // more generic
            'Payment',         // generic payment term
            'Transfer',
            'QR Payment',
        ];

        // Try to find the "Transaction Type" label and capture data nearby
        $pattern = '/Transaction Type[\s\S]{0,40}(.*?)(\s|$)/i';

        if (preg_match($pattern, $text, $matches)) {
            $possibleType = trim($matches[1]);

            // Check if the extracted type matches any known types
            foreach ($transactionTypes as $type) {
                if (stripos($possibleType, $type) !== false) {
                    return $type;
                }
            }
        }

        // Fallback approach: if "Transaction Type" is not nearby, search the whole text
        foreach ($transactionTypes as $type) {
            if (stripos($text, $type) !== false) {
                return $type;
            }
        }

        return null; // If no valid transaction type is found
    }

    public function show(Request $request): \Inertia\Response
    {
        $imageUrl = $request->session()->get('image_url');
        logger()->info('Extracted Text in show:', ['text' => $imageUrl]);
        // Retrieve the data from the session flash (set by with())
        return Inertia::render('Transactions/Show', [
            'reference_id' => $request->session()->get('reference_id'),
            'date' => $request->session()->get('date'),
            'amount' => $request->session()->get('amount'),
            'transaction_type' => $request->session()->get('transaction_type'),
            'isAdmin' => BouncerFacade::is(Auth::user())->an('admin'),
            'image_url' => $imageUrl,
        ]);
    }

    private function updateUserCounts(Transaction $transaction)
    {
        $user = $transaction->user;

        // Event start and end dates
        $eventStartDate = Carbon::create(2024, 11, 10); // 10th November 2024
        $eventEndDate = Carbon::create(2024, 12, 31);  // 31st December 2024

        // Parse transaction date
        $transactionDate = Carbon::parse($transaction->date);

        // Only update counts if the transaction is within the event date range
        if ($transactionDate->between($eventStartDate, $eventEndDate)) {
            // Calculate the number of days since the start of the event
            $daysSinceEventStart = $eventStartDate->diffInDays($transactionDate);

            // Calculate week number (1-based)
            $weekNumber = ceil(($daysSinceEventStart + 1) / 7);

            // Get the current week number based on today's date
            $daysSinceEventStartToday = Carbon::now()->diffInDays($eventStartDate);
            $currentWeekNumber = ceil(($daysSinceEventStartToday + 1) / 7);
            
            
            $transactionMonth = $transactionDate->month;
            $currentMonth = Carbon::now()->month;
            // Check if the transaction week is before the current week
            if($transactionMonth < $currentMonth)
                {
                    throw ValidationException::withMessages([
                        'date' => ['You cannot add transactions for past months.'],
                    ]);
                }
            else{
                if ($weekNumber < $currentWeekNumber) {
                    throw ValidationException::withMessages([
                        'date' => ['You cannot add transactions for past weeks.'],
                    ]);
                }
                else{
                    // Ensure the week number is valid (1-8)
                    if ($weekNumber >= 1 && $weekNumber <= 8) {
                        $weekColumn = 'week' . $weekNumber . '_count'; // e.g., week1_count, week2_count, etc.
                        $user->{$weekColumn} += 1; // Increment the specific week's count
                    }
                    // Update the total count for Oct, Nov, Dec
                    if ($transactionMonth >= 11 && $transactionMonth <= 12) {
                        $user->total_count += 1;
                    }
                    // Update the specific month's count
                    switch ($transactionMonth) {
                        case 11:
                            $user->nov_count += 1;
                            break;
                        case 12:
                            $user->dec_count += 1;
                            break;
                        default:
                            // If other months are needed, handle them here
                            break;
                    }

                    // Save the user model with the updated counts
                    $user->save();
                }
            }
        }
    }
}
