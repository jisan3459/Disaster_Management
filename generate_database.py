import csv
import random
from datetime import datetime, timedelta

def generate_dataset(filename, num_records=1000):
    headers = [
        'date', 'camp_id', 'occupancy', 'capacity',
        'food_stock', 'food_consumption_rate',
        'medicine_stock', 'medicine_consumption_rate',
        'active_volunteers', 'pending_tasks',
        'days_to_food_shortage', 'days_to_medicine_shortage', 'volunteer_shortage'
    ]

    start_date = datetime(2025, 1, 1)