@extends('errors.layout')
@section('title', 'Слишком много запросов')
@section('code', '429')
@section('message', 'Сервер получил от вас много обращений подряд. Подождите минуту и повторите.')
