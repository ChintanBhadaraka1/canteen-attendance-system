<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Canteen</title>
    <link rel="stylesheet" href="{{ asset('assets/css/Datatables/datatables.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/daterangepicker/daterangepicker.css') }}">
    {{-- <link rel="stylesheet" href="{{asset('assets/fonts/remixicon.css')}}"> --}}
    <link rel="stylesheet" href="{{ asset('assets/sweetalert2/css/sweetalert2.min.css') }}">
    <style>
        /* Overlay that covers the wrapper */
        .loader-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.7);
            z-index: 9999999999;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        /* When loading, disable scroll on entire page */
        .wrapper.loading {
            position: relative;
            /* to position overlay */
            overflow: hidden;
            /* prevent scrolling */
            height: 100vh;
        }

        /* Show overlay when loading */
        .wrapper.loading #loaderOverlay {
            display: flex !important;
        }

        /* Hide overlay by default */
        /* #loaderOverlay {
            display: none;
        } */


      
        /* Optional: hover effect */
        label.btn:hover {
            background-color: #f8f9fa;
            transition: background-color 0.3s ease;
        }

        input.btn-check:checked+label.btn {
            border-color: #0d6efd;
            background-color: #e7f1ff;
            color: #0d6efd;
            box-shadow: 0 0 10px rgb(13 110 253 / 0.5);
            transition: all 0.3s ease;
        }

        label.btn:hover {
            background-color: #f8f9fa;
            transition: background-color 0.3s ease;
        }

        .error-help-block{
            color: red;
        }
    </style>
</head>

<body>
