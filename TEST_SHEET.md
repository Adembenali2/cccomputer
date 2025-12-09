# Test Sheet - CCComputer Website

## Authentication

| ✅ | Feature | Page / URL | What to do (steps) | Expected result |
|----|---------|------------|--------------------|-----------------|
| [ ] | Login with valid credentials | /public/login.php | 1. Open the login page. 2. Enter a valid email address. 3. Enter the correct password. 4. Click "Connexion". | User is redirected to the dashboard and sees their account information. |
| [ ] | Login with invalid email | /public/login.php | 1. Open the login page. 2. Enter an email that does not exist. 3. Enter any password. 4. Click "Connexion". | An error message appears: "Adresse e-mail ou mot de passe incorrect." |
| [ ] | Login with invalid password | /public/login.php | 1. Open the login page. 2. Enter a valid email. 3. Enter an incorrect password. 4. Click "Connexion". | An error message appears: "Adresse e-mail ou mot de passe incorrect." |
| [ ] | Login with empty fields | /public/login.php | 1. Open the login page. 2. Leave email and password empty. 3. Click "Connexion". | An error message appears: "Veuillez remplir tous les champs." |
| [ ] | Login with disabled account | /public/login.php | 1. Open the login page. 2. Enter email of a disabled account. 3. Enter the correct password. 4. Click "Connexion". | An error message appears: "Votre compte est désactivé." |
| [ ] | Logout | Any page (via header menu) | 1. While logged in, click the "Déconnexion" link in the header menu. | User is logged out and redirected to the login page. |
| [ ] | Access protected page without login | Any protected page | 1. Open a protected page URL (e.g., /public/dashboard.php) while not logged in. | User is automatically redirected to the login page. |

## Navigation

| ✅ | Feature | Page / URL | What to do (steps) | Expected result |
|----|---------|------------|--------------------|-----------------|
| [ ] | Navigate to Dashboard | /public/dashboard.php | 1. Click "Accueil" in the header menu. | Dashboard page loads showing statistics and client list. |
| [ ] | Navigate to Messaging | /public/messagerie.php | 1. Click "Messagerie" in the header menu. | Messaging page opens showing the chatroom interface. |
| [ ] | Navigate to Agenda | /public/agenda.php | 1. Click "Agenda" in the header menu. | Agenda page loads showing SAV and deliveries scheduled. |
| [ ] | Navigate to Clients | /public/clients.php | 1. Click "Clients" in the header menu (if visible). | Clients list page loads showing all clients. |
| [ ] | Navigate to Stock | /public/stock.php | 1. Click "Stock" in the header menu (if visible). | Stock page loads showing products inventory. |
| [ ] | Navigate to Facturation | /public/facturation.php | 1. Click "Facturation" in the header menu (if visible for authorized roles). | Facturation page loads showing billing and payment information. |
| [ ] | Navigate to Maps | /public/maps.php | 1. Click "Cartes" in the header menu. | Maps page loads showing client locations on a map. |
| [ ] | Navigate to Profile | /public/profil.php | 1. Click "Profil" in the header menu. | Profile page loads showing user management interface. |
| [ ] | Toggle theme (dark/light) | Any page | 1. Click the theme toggle button in the header. | The page theme switches between dark and light mode. |
| [ ] | Mobile menu toggle | Any page (mobile view) | 1. On a mobile device or narrow screen, click the hamburger menu icon. | The navigation menu expands or collapses. |

## Dashboard

| ✅ | Feature | Page / URL | What to do (steps) | Expected result |
|----|---------|------------|--------------------|-----------------|
| [ ] | View dashboard statistics | /public/dashboard.php | 1. Log in and go to the dashboard. | Statistics are displayed showing counts of SAV to process, deliveries to do, and history entries. |
| [ ] | View client list on dashboard | /public/dashboard.php | 1. Scroll down on the dashboard page. | A list of clients is displayed with their information. |
| [ ] | Search clients on dashboard | /public/dashboard.php | 1. Use the search box on the dashboard. 2. Type a client name or number. | The client list filters to show matching results. |
| [ ] | Create new SAV from dashboard | /public/dashboard.php | 1. Click the button to create a new SAV. 2. Fill in the required fields (client, description, date). 3. Submit the form. | A new SAV is created and appears in the SAV list. |
| [ ] | Create new delivery from dashboard | /public/dashboard.php | 1. Click the button to create a new delivery. 2. Fill in the required fields (client, address, object, date). 3. Submit the form. | A new delivery is created and appears in the deliveries list. |

## Clients Management

| ✅ | Feature | Page / URL | What to do (steps) | Expected result |
|----|---------|------------|--------------------|-----------------|
| [ ] | View clients list | /public/clients.php | 1. Navigate to the clients page. | A table of all clients is displayed with their details. |
| [ ] | Search clients | /public/clients.php | 1. Use the search box on the clients page. 2. Type a client name, number, or address. | The client list filters to show matching results. |
| [ ] | Add new client | /public/clients.php | 1. Click the button to add a new client. 2. Fill in all required fields (company name, address, postal code, city, SIRET, email, phone, director name). 3. Submit the form. | A new client is created with an auto-generated client number (format C12345) and appears in the list. |
| [ ] | Add client with delivery address | /public/clients.php | 1. When adding a client, uncheck "Delivery address same as main address". 2. Enter a different delivery address. 3. Submit the form. | Client is created with a separate delivery address stored. |
| [ ] | View client details | /public/clients.php | 1. Click on a client in the list. | Client details page opens showing full information including photocopiers assigned. |
| [ ] | Edit client information | /public/clients.php | 1. Open a client's detail page. 2. Click edit button. 3. Modify any field. 4. Save changes. | Client information is updated and changes are visible. |
| [ ] | View client photocopiers | /public/clients.php | 1. Open a client's detail page. | List of photocopiers assigned to this client is displayed with their status. |
| [ ] | Filter clients by alert status | /public/clients.php | 1. Look for clients with alert indicators (missing or old meter readings). | Clients with alerts are highlighted or marked with a warning icon. |

## Messaging / Chatroom

| ✅ | Feature | Page / URL | What to do (steps) | Expected result |
|----|---------|------------|--------------------|-----------------|
| [ ] | View chatroom messages | /public/messagerie.php | 1. Navigate to the messaging page. | Recent messages are displayed in the chatroom interface. |
| [ ] | Send a text message | /public/messagerie.php | 1. Type a message in the input field. 2. Click the send button or press Enter. | The message appears in the chatroom with your name and timestamp. |
| [ ] | Mention a user in message | /public/messagerie.php | 1. Type "@" followed by a user's name. 2. Select the user from the dropdown suggestions. 3. Complete and send the message. | The message is sent and the mentioned user is highlighted in the message. |
| [ ] | Upload image in chatroom | /public/messagerie.php | 1. Click the image upload button. 2. Select an image file. 3. Send the message. | The image is uploaded and displayed in the chatroom message. |
| [ ] | View unread message count | Any page (header) | 1. Check the messaging icon in the header. | A badge shows the number of unread messages (if any). |
| [ ] | Link message to client | /public/messagerie.php | 1. When sending a message, select a client from the link options. 2. Send the message. | The message is linked to the selected client and can be accessed from the client's page. |
| [ ] | Link message to delivery | /public/messagerie.php | 1. When sending a message, select a delivery from the link options. 2. Send the message. | The message is linked to the selected delivery. |
| [ ] | Link message to SAV | /public/messagerie.php | 1. When sending a message, select a SAV from the link options. 2. Send the message. | The message is linked to the selected SAV. |
| [ ] | Auto-refresh messages | /public/messagerie.php | 1. Keep the messaging page open. 2. Wait a few seconds. | New messages from other users appear automatically without refreshing the page. |

## Agenda

| ✅ | Feature | Page / URL | What to do (steps) | Expected result |
|----|---------|------------|--------------------|-----------------|
| [ ] | View agenda (day view) | /public/agenda.php | 1. Navigate to the agenda page. 2. Select "Day" view mode. | SAV and deliveries scheduled for the selected day are displayed. |
| [ ] | View agenda (week view) | /public/agenda.php | 1. Navigate to the agenda page. 2. Select "Week" view mode. | SAV and deliveries scheduled for the week are displayed. |
| [ ] | View agenda (month view) | /public/agenda.php | 1. Navigate to the agenda page. 2. Select "Month" view mode. | SAV and deliveries scheduled for the month are displayed. |
| [ ] | Filter agenda by user | /public/agenda.php | 1. Select a specific user from the filter dropdown. | Only SAV and deliveries assigned to that user are shown. |
| [ ] | Change date in agenda | /public/agenda.php | 1. Use the date picker to select a different date. | The agenda updates to show events for the selected date. |
| [ ] | View SAV in agenda | /public/agenda.php | 1. Navigate to the agenda. | SAV entries are displayed with their reference, client, and scheduled intervention date. |
| [ ] | View deliveries in agenda | /public/agenda.php | 1. Navigate to the agenda. | Delivery entries are displayed with their reference, client, and scheduled date. |

## Facturation (Billing & Payments)

| ✅ | Feature | Page / URL | What to do (steps) | Expected result |
|----|---------|------------|--------------------|-----------------|
| [ ] | View billing page | /public/facturation.php | 1. Navigate to the facturation page (requires Admin, Dirigeant, or Chargé relation clients role). | Billing dashboard loads showing consumption charts and payment information. |
| [ ] | Search client for billing | /public/facturation.php | 1. Use the client search box. 2. Type a client name or reference. 3. Select a client from the dropdown. | Client is selected and their billing information is displayed. |
| [ ] | View consumption chart | /public/facturation.php | 1. Select a client (if required). 2. View the consumption chart section. | A chart displays consumption data (monthly or yearly view). |
| [ ] | Change chart granularity | /public/facturation.php | 1. Use the granularity dropdown (Month/Year). 2. Select a different option. | The chart updates to show data in the selected time period. |
| [ ] | View consumption table | /public/facturation.php | 1. Scroll to the consumption table section. | A table shows detailed consumption data with dates and amounts. |
| [ ] | View invoices list | /public/facturation.php | 1. Navigate to the invoices section. | A list of invoices is displayed with their status (draft, sent, paid, overdue, cancelled). |
| [ ] | View payments list | /public/facturation.php | 1. Navigate to the payments section. | A list of payments is displayed with amounts, dates, and payment methods. |
| [ ] | Create new payment | /public/facturation.php | 1. Click the button to create a payment. 2. Fill in payment details (client, amount, date, method). 3. Submit. | A new payment is created and appears in the payments list. |
| [ ] | Export payments to Excel | /public/facturation.php | 1. Click the "Exporter en Excel" button. | An Excel file is downloaded with payment data. |
| [ ] | View payment summary | /public/facturation.php | 1. View the summary section. | Summary statistics show total amounts, pending payments, and debts. |

## Maps & Route Planning

| ✅ | Feature | Page / URL | What to do (steps) | Expected result |
|----|---------|------------|--------------------|-----------------|
| [ ] | View maps page | /public/maps.php | 1. Navigate to the maps page (requires Admin or Dirigeant role). | A map is displayed showing client locations. |
| [ ] | Set starting point (GPS) | /public/maps.php | 1. Click "📍 Ma position" button. 2. Allow location access when prompted. | Your current location is set as the starting point on the map. |
| [ ] | Set starting point (click) | /public/maps.php | 1. Click "🖱️ Choisir sur la carte" button. 2. Click a location on the map. | The clicked location becomes the starting point. |
| [ ] | Search and add client to route | /public/maps.php | 1. Use the client search box. 2. Type a client name. 3. Click on a client from results. | The client is added to the selected clients list for the route. |
| [ ] | Calculate route | /public/maps.php | 1. Set a starting point. 2. Add at least one client. 3. Click "Calculer l'itinéraire". | A route is calculated and displayed on the map connecting all points. |
| [ ] | View route details | /public/maps.php | 1. After calculating a route, view the route information panel. | Route details show total distance, estimated time, and turn-by-turn directions. |
| [ ] | Clear starting point | /public/maps.php | 1. Click "❌ Effacer" button. | The starting point is removed from the map. |
| [ ] | Remove client from route | /public/maps.php | 1. Click the remove button next to a selected client. | The client is removed from the route planning list. |

## Stock Management

| ✅ | Feature | Page / URL | What to do (steps) | Expected result |
|----|---------|------------|--------------------|-----------------|
| [ ] | View stock page | /public/stock.php | 1. Navigate to the stock page. | Stock page loads showing different product categories (Paper, Toner, LCD, PC, Photocopiers). |
| [ ] | View paper stock | /public/stock.php | 1. Navigate to the stock page. 2. View the Paper section. | A table shows all paper products with brand, model, weight, barcode, and stock quantity. |
| [ ] | View toner stock | /public/stock.php | 1. Navigate to the stock page. 2. View the Toner section. | A table shows all toner products with brand, model, color, barcode, and stock quantity. |
| [ ] | View LCD stock | /public/stock.php | 1. Navigate to the stock page. 2. View the LCD section. | A table shows all LCD products with brand, model, size, resolution, and stock quantity. |
| [ ] | View PC stock | /public/stock.php | 1. Navigate to the stock page. 2. View the PC section. | A table shows all PC products with specifications and stock quantity. |
| [ ] | View photocopiers stock | /public/stock.php | 1. Navigate to the stock page. 2. View the Photocopiers section. | A table shows photocopiers not assigned to clients with their status and meter readings. |
| [ ] | Add product to stock | /public/stock.php | 1. Click the "Add" button for a product category. 2. Fill in product details (brand, model, etc.). 3. Submit the form. | The product is added to the stock and appears in the list. |
| [ ] | Update stock quantity | /public/stock.php | 1. Find a product in the stock list. 2. Click to adjust quantity. 3. Enter the new quantity or adjustment. 4. Save. | The stock quantity is updated and the change is recorded. |
| [ ] | Search products in stock | /public/stock.php | 1. Use the search box on the stock page. 2. Type a product name, brand, or barcode. | The product list filters to show matching results. |
| [ ] | Print QR code labels | /public/stock.php | 1. Find a product with a QR code. 2. Click the print labels button. | A printable page opens with 24 QR code labels in a grid format. |
| [ ] | View stock movements | /public/stock.php | 1. Click on a product to view details. | Stock movement history is displayed showing additions, removals, and adjustments. |

## Barcode Scanning

| ✅ | Feature | Page / URL | What to do (steps) | Expected result |
|----|---------|------------|--------------------|-----------------|
| [ ] | Open barcode scanner | /public/scan_barcode.php | 1. Navigate to the barcode scanner page. | Scanner page loads with camera controls. |
| [ ] | Start camera for scanning | /public/scan_barcode.php | 1. Click "📹 Démarrer la caméra" button. 2. Allow camera access when prompted. | Camera activates and shows a scanning frame on the screen. |
| [ ] | Scan a barcode | /public/scan_barcode.php | 1. Start the camera. 2. Point the camera at a product barcode. | The barcode is detected and product information is displayed below. |
| [ ] | View scanned product details | /public/scan_barcode.php | 1. After scanning, view the product result section. | Product details are shown including name, type, specifications, and stock quantity. |
| [ ] | Stop camera scanning | /public/scan_barcode.php | 1. Click "⏹️ Arrêter" button. | Camera stops and scanning is disabled. |
| [ ] | Scan multiple products | /public/scan_barcode.php | 1. Scan a product. 2. Scan another product without stopping. | Each scanned product information is displayed sequentially. |

## SAV (Customer Service)

| ✅ | Feature | Page / URL | What to do (steps) | Expected result |
|----|---------|------------|--------------------|-----------------|
| [ ] | View SAV list | /public/sav.php | 1. Navigate to the SAV page. | A list of all SAV entries is displayed with their status, priority, and client information. |
| [ ] | Create new SAV | /public/sav.php | 1. Click the button to create a new SAV. 2. Fill in required fields (client, description, opening date, reference). 3. Submit the form. | A new SAV is created and appears in the list with status "ouvert" (open). |
| [ ] | Assign technician to SAV | /public/sav.php | 1. Open a SAV entry. 2. Select a technician from the dropdown. 3. Save changes. | The technician is assigned to the SAV and appears in the entry details. |
| [ ] | Update SAV status | /public/sav.php | 1. Open a SAV entry. 2. Change the status (open, in progress, resolved, cancelled). 3. Save changes. | The SAV status is updated and the entry reflects the new status. |
| [ ] | Set SAV priority | /public/sav.php | 1. Open a SAV entry. 2. Change the priority (low, normal, high, urgent). 3. Save changes. | The SAV priority is updated. |
| [ ] | Set intervention date | /public/sav.php | 1. Open a SAV entry. 2. Set a scheduled intervention date. 3. Save changes. | The intervention date is saved and the SAV appears in the agenda on that date. |
| [ ] | Add parts used in SAV | /public/sav.php | 1. Open a SAV entry. 2. Add parts (paper, toner, LCD, PC) used during intervention. 3. Save. | Parts are recorded and linked to the SAV. |
| [ ] | Close SAV | /public/sav.php | 1. Open a SAV entry. 2. Change status to "resolu" (resolved). 3. Save changes. | SAV is closed and the closing date is automatically set to today. |
| [ ] | Search SAV entries | /public/sav.php | 1. Use the search box on the SAV page. 2. Type a reference, client name, or description. | The SAV list filters to show matching results. |
| [ ] | Filter SAV by status | /public/sav.php | 1. Use status filter dropdown. 2. Select a status (open, in progress, resolved, cancelled). | Only SAV entries with the selected status are displayed. |
| [ ] | Filter SAV by priority | /public/sav.php | 1. Use priority filter dropdown. 2. Select a priority level. | Only SAV entries with the selected priority are displayed. |
| [ ] | View SAV linked to client | /public/sav.php | 1. Click on a SAV entry. | Client information is displayed along with the SAV details. |
| [ ] | View SAV linked to photocopier | /public/sav.php | 1. Open a SAV entry linked to a photocopier (by MAC address). | Photocopier information is displayed in the SAV details. |

## Deliveries (Livraisons)

| ✅ | Feature | Page / URL | What to do (steps) | Expected result |
|----|---------|------------|--------------------|-----------------|
| [ ] | View deliveries list | /public/livraison.php | 1. Navigate to the deliveries page. | A list of all deliveries is displayed with their status, dates, and client information. |
| [ ] | Create new delivery | /public/livraison.php | 1. Click the button to create a new delivery. 2. Fill in required fields (client, delivery address, object, scheduled date, reference). 3. Submit the form. | A new delivery is created and appears in the list with status "planifiee" (planned). |
| [ ] | Assign delivery person | /public/livraison.php | 1. Open a delivery entry. 2. Select a delivery person from the dropdown. 3. Save changes. | The delivery person is assigned to the delivery. |
| [ ] | Update delivery status | /public/livraison.php | 1. Open a delivery entry. 2. Change the status (planned, in progress, delivered, cancelled). 3. Save changes. | The delivery status is updated and the entry reflects the new status. |
| [ ] | Mark delivery as delivered | /public/livraison.php | 1. Open a delivery entry. 2. Change status to "livree" (delivered). 3. Save changes. | Delivery is marked as delivered and the actual delivery date is automatically set to today. |
| [ ] | Link product to delivery | /public/livraison.php | 1. When creating or editing a delivery, select a product type (paper, toner, LCD, PC). 2. Select the specific product. 3. Enter quantity. 4. Save. | The product is linked to the delivery and will be deducted from stock when delivered. |
| [ ] | Search deliveries | /public/livraison.php | 1. Use the search box on the deliveries page. 2. Type a reference, client name, or delivery address. | The deliveries list filters to show matching results. |
| [ ] | Filter deliveries by status | /public/livraison.php | 1. Use status filter dropdown. 2. Select a status (planned, in progress, delivered, cancelled). | Only deliveries with the selected status are displayed. |
| [ ] | View delivery details | /public/livraison.php | 1. Click on a delivery entry. | Full delivery details are displayed including client, address, product, and dates. |
| [ ] | Add delivery comment | /public/livraison.php | 1. Open a delivery entry. 2. Add a comment in the comment field. 3. Save changes. | The comment is saved and displayed in the delivery details. |

## Profile & User Management

| ✅ | Feature | Page / URL | What to do (steps) | Expected result |
|----|---------|------------|--------------------|-----------------|
| [ ] | View users list | /public/profil.php | 1. Navigate to the profile page. | A list of all users is displayed with their names, emails, roles, and status. |
| [ ] | Create new user | /public/profil.php | 1. Click the button to create a new user. 2. Fill in required fields (email, password, first name, last name, role, start date). 3. Submit the form. | A new user is created and appears in the users list. |
| [ ] | Edit user information | /public/profil.php | 1. Click on a user in the list. 2. Click edit button. 3. Modify any field. 4. Save changes. | User information is updated and changes are visible. |
| [ ] | Change user role | /public/profil.php | 1. Edit a user. 2. Change their role (Chargé relation clients, Livreur, Technicien, Secrétaire, Dirigeant, Admin). 3. Save changes. | User role is updated and their permissions change accordingly. |
| [ ] | Activate user account | /public/profil.php | 1. Edit a user. 2. Change status to "actif" (active). 3. Save changes. | User account is activated and they can log in. |
| [ ] | Deactivate user account | /public/profil.php | 1. Edit a user. 2. Change status to "inactif" (inactive). 3. Save changes. | User account is deactivated and they cannot log in. |
| [ ] | Search users | /public/profil.php | 1. Use the search box on the profile page. 2. Type a user's name or email. | The users list filters to show matching results. |
| [ ] | View user permissions | /public/profil.php | 1. Click on a user to view details. 2. View the permissions section. | A list of page permissions is displayed showing which pages the user can access. |
| [ ] | Edit user permissions | /public/profil.php | 1. Edit a user. 2. Modify page permissions (allow/deny access to specific pages). 3. Save changes. | User permissions are updated and access is restricted or granted accordingly. |
| [ ] | View online users | /public/profil.php | 1. Navigate to the profile page. | A count or list of users currently online (active in last 5 minutes) is displayed. |

## History & Logs

| ✅ | Feature | Page / URL | What to do (steps) | Expected result |
|----|---------|------------|--------------------|-----------------|
| [ ] | View history page | /public/historique.php | 1. Navigate to the history page (requires Admin or Dirigeant role). | A list of all user actions is displayed with timestamps and details. |
| [ ] | Search history by user | /public/historique.php | 1. Use the user search box. 2. Type a user's name. | History entries are filtered to show only actions by that user. |
| [ ] | Filter history by date | /public/historique.php | 1. Use the date picker. 2. Select a specific date. | History entries are filtered to show only actions on that date. |
| [ ] | View action details | /public/historique.php | 1. Click on a history entry. | Full details of the action are displayed including user, action type, details, IP address, and timestamp. |

## Photocopiers Details

| ✅ | Feature | Page / URL | What to do (steps) | Expected result |
|----|---------|------------|--------------------|-----------------|
| [ ] | View photocopier details | /public/photocopieurs_details.php | 1. Navigate to a photocopier details page (via client page or direct URL with MAC or serial number). | Photocopier information is displayed including model, serial number, MAC address, and status. |
| [ ] | View meter readings | /public/photocopieurs_details.php | 1. Open a photocopier details page. 2. Scroll to the meter readings section. | A table shows historical meter readings with dates, page counts (total, color, black & white), and toner levels. |
| [ ] | Assign photocopier to client | /public/photocopieurs_details.php | 1. Open a photocopier details page. 2. Click "Assign to client" button. 3. Select a client from the dropdown. 4. Submit. | The photocopier is assigned to the selected client and appears in the client's photocopiers list. |
| [ ] | View photocopier status | /public/photocopieurs_details.php | 1. Open a photocopier details page. | Current status is displayed (online, offline, error, etc.) based on the latest meter reading. |
| [ ] | View toner levels | /public/photocopieurs_details.php | 1. Open a photocopier details page. 2. View the toner levels section. | Toner levels for black, cyan, magenta, and yellow are displayed as percentages. |
| [ ] | View page count statistics | /public/photocopieurs_details.php | 1. Open a photocopier details page. 2. View the statistics section. | Statistics show total pages, color pages, black & white pages, copies, prints, and fax pages. |

## Client File (Detailed View)

| ✅ | Feature | Page / URL | What to do (steps) | Expected result |
|----|---------|------------|--------------------|-----------------|
| [ ] | View client detailed file | /public/client_fiche.php | 1. Navigate to a client's detailed file page (requires Admin or Dirigeant role). | Complete client information is displayed including all contact details, addresses, and documents. |
| [ ] | Edit client file | /public/client_fiche.php | 1. Open a client file. 2. Click edit button. 3. Modify any field. 4. Save changes. | Client information is updated and changes are saved. |
| [ ] | Upload client document | /public/client_fiche.php | 1. Open a client file. 2. Use the document upload section. 3. Select a PDF or image file. 4. Upload. | The document is uploaded and linked to the client, accessible from the client file. |
| [ ] | View uploaded documents | /public/client_fiche.php | 1. Open a client file. 2. Scroll to the documents section. | All uploaded documents (PDFs and images) are displayed with download links. |
| [ ] | Update client payment method | /public/client_fiche.php | 1. Edit a client file. 2. Change the payment method (cash, check, transfer, card). 3. Save changes. | Payment method is updated for the client. |
| [ ] | Update client offer type | /public/client_fiche.php | 1. Edit a client file. 2. Change the offer type (pack bronze, pack argent). 3. Save changes. | Offer type is updated for the client. |

## Print Labels

| ✅ | Feature | Page / URL | What to do (steps) | Expected result |
|----|---------|------------|--------------------|-----------------|
| [ ] | View print labels page | /public/print_labels.php | 1. Navigate to print labels page (via stock page or direct URL with product type and ID). | A printable page is displayed showing 24 QR code labels in a 3x8 grid format. |
| [ ] | Print QR code labels | /public/print_labels.php | 1. Open the print labels page. 2. Click the print button or use browser print (Ctrl+P). 3. Print the page. | Labels are printed on A4 paper with QR codes, product names, and barcodes. |
| [ ] | Preview labels before printing | /public/print_labels.php | 1. Open the print labels page. | Labels are displayed in a preview format showing how they will appear when printed. |

## General Features

| ✅ | Feature | Page / URL | What to do (steps) | Expected result |
|----|---------|------------|--------------------|-----------------|
| [ ] | Responsive design (mobile) | Any page | 1. Open the website on a mobile device or resize browser window to mobile size. | The website adapts to the smaller screen with a mobile-friendly layout and hamburger menu. |
| [ ] | Responsive design (tablet) | Any page | 1. Open the website on a tablet or resize browser window to tablet size. | The website displays appropriately for tablet screen size. |
| [ ] | Responsive design (desktop) | Any page | 1. Open the website on a desktop computer. | The website displays in full desktop layout with all features visible. |
| [ ] | Session timeout | Any page | 1. Log in to the website. 2. Leave the page idle for an extended period (30+ minutes). 3. Try to perform an action. | User is logged out due to session timeout and redirected to login page. |
| [ ] | Access denied for unauthorized role | Restricted pages | 1. Log in with a user role that doesn't have access to a specific page. 2. Try to access that page directly via URL. | An access denied message is displayed or user is redirected to an authorized page. |
| [ ] | Form validation | Any form page | 1. Try to submit a form with empty required fields. | Error messages appear indicating which fields are required or invalid. |
| [ ] | CSRF protection | Any form page | 1. Try to submit a form with an invalid or missing CSRF token. | Form submission is rejected with a security error message. |

